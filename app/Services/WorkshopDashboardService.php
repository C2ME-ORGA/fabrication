<?php

namespace App\Services;

use App\DTO\WorkOrderTimeSummary;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
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
