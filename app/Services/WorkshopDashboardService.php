<?php

namespace App\Services;

use App\DTO\WorkOrderTimeSummary;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityControlStatus;
use App\Enums\QualityDeviationStatus;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\NonConformity;
use App\Models\OperationTimeEntry;
use App\Models\QualityDeviation;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agrégats du tableau de bord atelier et de la vue responsable.
 *
 * Les indicateurs sont calculés à partir des statuts réels et des temps
 * effectifs fournis par OperationTimeAggregationService.
 */
class WorkshopDashboardService
{
    protected const STALE_AFTER_HOURS = 24;

    public function __construct(
        protected OperationTimeAggregationService $times,
        protected QualityControlEvaluationService $quality,
    ) {}

    /**
     * @return array{
     *     released_work_orders: int,
     *     operations_by_status: array<string, int>,
     *     active_timers: int,
     *     total_real_seconds: int
     * }
     */
    public function summary(): array
    {
        $workOrders = $this->activeWorkOrders();

        $rawCounts = WorkOrderOperation::query()
            ->whereIn('work_order_id', $workOrders->pluck('id'))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusCounts = [];

        foreach (WorkshopOperationStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) ($rawCounts[$status->value] ?? 0);
        }

        $activeTimers = OperationTimeEntry::query()
            ->whereNull('ended_at')
            ->count();

        $summaries = $this->times->forWorkOrders($workOrders);

        return [
            'released_work_orders' => $workOrders->count(),
            'operations_by_status' => $statusCounts,
            'active_timers' => $activeTimers,
            'total_real_seconds' => array_sum(array_map(
                fn ($summary): int => $summary->realSeconds,
                $summaries,
            )),
        ];
    }

    /**
     * @return array{
     *     blocked_operations: Collection<int, WorkOrderOperation>,
     *     overdue_operations: Collection<int, array>,
     *     work_orders_in_progress: Collection<int, array>,
     *     stale_operations: Collection<int, WorkOrderOperation>,
     *     active_sessions: Collection<int, OperationTimeEntry>,
     *     active_operators_count: int,
     *     work_order_summaries: array<int, WorkOrderTimeSummary>,
     *     work_orders: Collection<int, WorkOrder>
     * }
     */
    public function supervision(): array
    {
        $workOrders = $this->activeWorkOrders(withJob: true);
        $operations = WorkOrderOperation::query()
            ->whereIn('work_order_id', $workOrders->pluck('id'))
            ->with(['workOrder.job', 'timeEntries.user'])
            ->get();

        $summaries = $this->times->forWorkOrders($workOrders);
        $threshold = now()->subHours(self::STALE_AFTER_HOURS);

        $blocked = $operations
            ->where('status', WorkshopOperationStatus::Blocked)
            ->sortByDesc(fn (WorkOrderOperation $operation) => $operation->blocked_at?->getTimestamp() ?? 0)
            ->take(20)
            ->values();

        $overdue = $operations
            ->filter(fn (WorkOrderOperation $operation): bool => (int) $operation->estimated_duration_minutes > 0)
            ->map(function (WorkOrderOperation $operation): array {
                $real = $this->times->realSecondsFromEntries($operation->timeEntries);
                $estimated = max(0, (int) $operation->estimated_duration_minutes) * 60;
                $variance = $real - $estimated;

                return [
                    'operation' => $operation,
                    'real_seconds' => $real,
                    'estimated_seconds' => $estimated,
                    'variance_seconds' => $variance,
                    'consumption_percent' => $estimated > 0 ? round(($real / $estimated) * 100, 2) : null,
                ];
            })
            ->filter(fn (array $row): bool => $row['variance_seconds'] > 0)
            ->sortByDesc('variance_seconds')
            ->take(20)
            ->values();

        $workOrdersInProgress = $workOrders
            ->filter(fn (WorkOrder $workOrder): bool => $operations
                ->where('work_order_id', $workOrder->getKey())
                ->where('status', WorkshopOperationStatus::InProgress)
                ->isNotEmpty())
            ->map(fn (WorkOrder $workOrder): array => [
                'work_order' => $workOrder,
                'in_progress_count' => $operations
                    ->where('work_order_id', $workOrder->getKey())
                    ->where('status', WorkshopOperationStatus::InProgress)
                    ->count(),
                'blocked_count' => $operations
                    ->where('work_order_id', $workOrder->getKey())
                    ->where('status', WorkshopOperationStatus::Blocked)
                    ->count(),
            ])
            ->sortByDesc('in_progress_count')
            ->values();

        $stale = $operations
            ->map(fn (WorkOrderOperation $operation): array => [
                'operation' => $operation,
                'last_activity' => $this->lastActivityAt($operation),
            ])
            ->filter(fn (array $row): bool => in_array($row['operation']->status, [
                WorkshopOperationStatus::Ready,
                WorkshopOperationStatus::InProgress,
                WorkshopOperationStatus::Paused,
                WorkshopOperationStatus::Blocked,
            ], true) && $row['last_activity']->lt($threshold))
            ->sortBy(fn (array $row): int => $row['last_activity']->getTimestamp())
            ->take(20)
            ->pluck('operation')
            ->values();

        $activeSessions = OperationTimeEntry::query()
            ->whereNull('ended_at')
            ->with(['user', 'operation.workOrder.job'])
            ->orderBy('started_at')
            ->get();

        return [
            'blocked_operations' => $blocked,
            'overdue_operations' => $overdue,
            'work_orders_in_progress' => $workOrdersInProgress,
            'stale_operations' => $stale,
            'active_sessions' => $activeSessions,
            'active_operators_count' => $activeSessions->pluck('user_id')->unique()->count(),
            'work_order_summaries' => $summaries,
            'work_orders' => $workOrders,
        ];
    }

    /**
     * Indicateurs qualité du tableau de bord atelier.
     *
     * Tous les agrégats portent sur les OF actuellement exécutables. Les
     * résultats et statuts sont lus, jamais recalculés : le statut des
     * contrôles est maintenu par QualityControlEvaluationService.
     *
     * @return array{
     *     controls_total: int,
     *     controls_pending: int,
     *     controls_failed: int,
     *     controls_awaiting_validation: int,
     *     controls_late: int,
     *     conformity_rate: float|null,
     *     non_conformities_open: int,
     *     non_conformities_by_severity: array<string, int>,
     *     deviations_active: int
     * }
     */
    public function qualitySummary(): array
    {
        $workOrderIds = $this->activeWorkOrders()->pluck('id');

        $controls = WorkOrderQualityControl::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->where('is_mandatory', true)
            ->where('status', '!=', QualityControlStatus::Cancelled->value)
            ->with('points.results.validations')
            ->get();

        $passed = $controls->filter(
            fn (WorkOrderQualityControl $control): bool => $control->status === QualityControlStatus::Passed,
        )->count();
        $failed = $controls->filter(
            fn (WorkOrderQualityControl $control): bool => $control->status === QualityControlStatus::Failed,
        )->count();
        $completed = $passed + $failed;

        $openNonConformities = NonConformity::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->whereNotIn('status', [
                NonConformityStatus::Closed->value,
                NonConformityStatus::Cancelled->value,
            ])
            ->get();

        $bySeverity = [];

        foreach (NonConformitySeverity::cases() as $severity) {
            $bySeverity[$severity->value] = $openNonConformities
                ->where('severity', $severity)
                ->count();
        }

        $activeDeviations = QualityDeviation::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->where('status', QualityDeviationStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->count();

        return [
            'controls_total' => $controls->count(),
            'controls_pending' => $controls->filter(
                fn (WorkOrderQualityControl $control): bool => in_array($control->status, [
                    QualityControlStatus::Pending,
                    QualityControlStatus::InProgress,
                ], true),
            )->count(),
            'controls_failed' => $failed,
            'controls_awaiting_validation' => $controls
                ->filter(fn (WorkOrderQualityControl $control): bool => $this->hasPendingValidation($control))
                ->count(),
            'controls_late' => $controls
                ->filter(fn (WorkOrderQualityControl $control): bool => $this->isLate($control))
                ->count(),
            'conformity_rate' => $completed > 0
                ? round(($passed / $completed) * 100, 1)
                : null,
            'non_conformities_open' => $openNonConformities->count(),
            'non_conformities_by_severity' => $bySeverity,
            'deviations_active' => $activeDeviations,
        ];
    }

    /**
     * Files d'attention qualité pour la vue responsable.
     *
     * @return array{
     *     pending_controls: Collection<int, WorkOrderQualityControl>,
     *     pending_controls_count: int,
     *     overdue_controls: Collection<int, WorkOrderQualityControl>,
     *     overdue_controls_count: int,
     *     awaiting_validation_controls: Collection<int, WorkOrderQualityControl>,
     *     awaiting_validation_controls_count: int,
     *     overdue_non_conformities: Collection<int, NonConformity>,
     *     overdue_non_conformities_count: int,
     *     expiring_deviations: Collection<int, QualityDeviation>,
     *     expiring_deviations_count: int
     * }
     */
    public function qualitySupervision(): array
    {
        $workOrderIds = $this->activeWorkOrders()->pluck('id');

        $base = WorkOrderQualityControl::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->where('status', '!=', QualityControlStatus::Cancelled->value);

        $pendingQuery = (clone $base)->whereIn('status', [
            QualityControlStatus::Pending->value,
            QualityControlStatus::InProgress->value,
        ]);

        $overdueQuery = (clone $base)
            ->whereNotNull('required_by_at')
            ->where('required_by_at', '<', now())
            ->whereIn('status', [
                QualityControlStatus::Pending->value,
                QualityControlStatus::InProgress->value,
                QualityControlStatus::Failed->value,
            ]);

        $pendingControls = (clone $pendingQuery)
            ->with(['workOrder.job', 'operation'])
            ->orderBy('required_by_at')
            ->orderBy('id')
            ->take(20)
            ->get();

        $overdueControls = (clone $overdueQuery)
            ->with(['workOrder.job', 'operation'])
            ->orderBy('required_by_at')
            ->take(20)
            ->get();

        $allAwaitingValidation = (clone $base)
            ->whereIn('status', [
                QualityControlStatus::Pending->value,
                QualityControlStatus::InProgress->value,
                QualityControlStatus::Failed->value,
            ])
            ->with(['workOrder.job', 'operation', 'points.results.validations'])
            ->orderBy('id')
            ->get()
            ->filter(fn (WorkOrderQualityControl $control): bool => $this->hasPendingValidation($control));

        $awaitingValidationControls = $allAwaitingValidation->take(20)->values();

        $overdueNonConformities = NonConformity::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->whereNotIn('status', [
                NonConformityStatus::Closed->value,
                NonConformityStatus::Cancelled->value,
            ])
            ->where(function ($query): void {
                $query->whereDate('due_date', '<', today())
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('verification_due_at')
                            ->where('verification_due_at', '<', now());
                    });
            })
            ->with('workOrder')
            ->orderBy('due_date')
            ->orderBy('id')
            ->take(20)
            ->get();

        $expiringDeviations = QualityDeviation::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->where('status', QualityDeviationStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(7))
            ->with(['workOrder', 'targets'])
            ->orderBy('expires_at')
            ->take(20)
            ->get();

        return [
            'pending_controls' => $pendingControls,
            'pending_controls_count' => (clone $pendingQuery)->count(),
            'overdue_controls' => $overdueControls,
            'overdue_controls_count' => (clone $overdueQuery)->count(),
            'awaiting_validation_controls' => $awaitingValidationControls,
            'awaiting_validation_controls_count' => $allAwaitingValidation->count(),
            'overdue_non_conformities' => $overdueNonConformities,
            'overdue_non_conformities_count' => (clone $overdueNonConformities)->count(),
            'expiring_deviations' => $expiringDeviations,
            'expiring_deviations_count' => (clone $expiringDeviations)->count(),
        ];
    }

    protected function hasPendingValidation(WorkOrderQualityControl $control): bool
    {
        return $this->quality->progress($control)->contains(
            fn ($progress): bool => $progress->hasPendingValidation,
        );
    }

    protected function isLate(WorkOrderQualityControl $control): bool
    {
        return $control->required_by_at !== null
            && $control->required_by_at->isPast()
            && in_array($control->status, [
                QualityControlStatus::Pending,
                QualityControlStatus::InProgress,
                QualityControlStatus::Failed,
            ], true);
    }

    /**
     * Dernière activité connue d'une opération : mise à jour de l'opération,
     * ou événement de chronométrage (démarrage, arrêt, correction) survenu
     * après cette mise à jour.
     */
    protected function lastActivityAt(WorkOrderOperation $operation): CarbonInterface
    {
        $lastEntryActivity = $operation->timeEntries
            ->flatMap(fn (OperationTimeEntry $entry): array => [
                $entry->updated_at,
                $entry->started_at,
                $entry->ended_at,
            ])
            ->filter()
            ->max();

        return collect([$operation->updated_at, $lastEntryActivity])
            ->filter()
            ->max() ?? now();
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    protected function activeWorkOrders(bool $withJob = false): Collection
    {
        return WorkOrder::query()
            ->whereIn('status', [
                WorkOrderStatus::Released,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::QualityCheck,
            ])
            ->when($withJob, fn ($query) => $query->with('job'))
            ->get();
    }
}
