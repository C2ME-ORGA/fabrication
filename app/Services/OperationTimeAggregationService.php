<?php

namespace App\Services;

use App\DTO\OperationTimeSummary;
use App\DTO\WorkOrderTimeSummary;
use App\Enums\OperationTimeEntryType;
use App\Models\OperationTimeEntry;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Calcul des agrégats de temps sans stockage redondant.
 *
 * Une correction `adjustment` remplace, pour le calcul, la durée de son
 * entrée d'origine. Les corrections ne sont donc jamais additionnées en plus
 * de l'original : seule la dernière correction rattachée à une entrée est
 * utilisée. Les sessions actives ne sont pas comptées.
 */
class OperationTimeAggregationService
{
    public function forOperation(WorkOrderOperation $operation): OperationTimeSummary
    {
        $entries = $operation->timeEntries()->get();
        $computed = $this->compute($entries);
        $estimated = $this->minutesToSeconds($operation->estimated_duration_minutes);

        return new OperationTimeSummary(
            operationId: (int) $operation->getKey(),
            realSeconds: $computed['realSeconds'],
            estimatedSeconds: $estimated,
            varianceSeconds: $computed['realSeconds'] - $estimated,
            consumptionPercent: $this->percentage($computed['realSeconds'], $estimated),
            calendarSeconds: $computed['calendarSeconds'],
            byOperator: $computed['byOperator'],
            entryCount: $computed['entryCount'],
        );
    }

    public function forWorkOrder(WorkOrder $workOrder): WorkOrderTimeSummary
    {
        $operations = $workOrder->operations()->get(['id', 'estimated_duration_minutes']);
        $entries = OperationTimeEntry::query()
            ->whereIn('work_order_operation_id', $operations->pluck('id'))
            ->get();

        $computed = $this->compute($entries);
        $estimated = (int) $operations->sum(
            fn (WorkOrderOperation $operation): int => $this->minutesToSeconds($operation->estimated_duration_minutes),
        );

        return new WorkOrderTimeSummary(
            workOrderId: (int) $workOrder->getKey(),
            realSeconds: $computed['realSeconds'],
            estimatedSeconds: $estimated,
            varianceSeconds: $computed['realSeconds'] - $estimated,
            consumptionPercent: $this->percentage($computed['realSeconds'], $estimated),
            calendarSeconds: $computed['calendarSeconds'],
            byOperator: $computed['byOperator'],
            byOperation: $computed['byOperation'],
            entryCount: $computed['entryCount'],
        );
    }

    /**
     * Calcule les agrégats d'un ensemble d'OF avec un nombre constant de
     * requêtes : opérations puis entrées de temps.
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     * @return array<int, WorkOrderTimeSummary>
     */
    public function forWorkOrders(Collection $workOrders): array
    {
        if ($workOrders->isEmpty()) {
            return [];
        }

        $operations = WorkOrderOperation::query()
            ->whereIn('work_order_id', $workOrders->pluck('id'))
            ->get(['id', 'work_order_id', 'estimated_duration_minutes']);

        $entries = OperationTimeEntry::query()
            ->whereIn('work_order_operation_id', $operations->pluck('id'))
            ->get();

        $entriesByOperation = $entries->groupBy('work_order_operation_id');
        $operationsByWorkOrder = $operations->groupBy('work_order_id');
        $summaries = [];

        foreach ($workOrders as $workOrder) {
            /** @var Collection<int, WorkOrderOperation> $workOrderOperations */
            $workOrderOperations = $operationsByWorkOrder->get(
                $workOrder->getKey(),
                new Collection,
            );

            /** @var Collection<int, OperationTimeEntry> $workOrderEntries */
            $workOrderEntries = new Collection;

            foreach ($workOrderOperations as $operation) {
                $workOrderEntries = $workOrderEntries->merge(
                    $entriesByOperation->get($operation->getKey(), new Collection),
                );
            }

            $computed = $this->compute($workOrderEntries);
            $estimated = (int) $workOrderOperations->sum(
                fn (WorkOrderOperation $operation): int => $this->minutesToSeconds($operation->estimated_duration_minutes),
            );

            $summaries[(int) $workOrder->getKey()] = new WorkOrderTimeSummary(
                workOrderId: (int) $workOrder->getKey(),
                realSeconds: $computed['realSeconds'],
                estimatedSeconds: $estimated,
                varianceSeconds: $computed['realSeconds'] - $estimated,
                consumptionPercent: $this->percentage($computed['realSeconds'], $estimated),
                calendarSeconds: $computed['calendarSeconds'],
                byOperator: $computed['byOperator'],
                byOperation: $computed['byOperation'],
                entryCount: $computed['entryCount'],
            );
        }

        return $summaries;
    }

    /**
     * Temps réel calculé à partir d'une collection déjà chargée.
     *
     * @param  Collection<int, OperationTimeEntry>  $entries
     */
    public function realSecondsFromEntries(Collection $entries): int
    {
        return $this->compute($entries)['realSeconds'];
    }

    /**
     * @param  Collection<int, OperationTimeEntry>  $entries
     * @return array{
     *     realSeconds: int,
     *     byOperator: array<int, int>,
     *     byOperation: array<int, int>,
     *     calendarSeconds: int,
     *     entryCount: int
     * }
     */
    protected function compute(Collection $entries): array
    {
        $latestAdjustments = $entries
            ->filter(fn (OperationTimeEntry $entry): bool => $entry->entry_type === OperationTimeEntryType::Adjustment
                && $entry->original_entry_id !== null
                && $entry->duration_seconds !== null)
            ->groupBy('original_entry_id')
            ->map(fn (Collection $group): OperationTimeEntry => $group->sortByDesc('id')->first());

        $originals = $entries->filter(
            fn (OperationTimeEntry $entry): bool => in_array($entry->entry_type, [
                OperationTimeEntryType::Timer,
                OperationTimeEntryType::Manual,
            ], true)
                && $entry->ended_at !== null
                && $entry->duration_seconds !== null,
        );

        $realSeconds = 0;
        $byOperator = [];
        $byOperation = [];
        $starts = [];
        $ends = [];

        foreach ($originals as $entry) {
            $adjustment = $latestAdjustments->get($entry->getKey());
            $duration = $adjustment instanceof OperationTimeEntry
                ? (int) $adjustment->duration_seconds
                : (int) $entry->duration_seconds;

            $realSeconds += $duration;
            $operatorId = (int) $entry->user_id;
            $operationId = (int) $entry->work_order_operation_id;

            $byOperator[$operatorId] = ($byOperator[$operatorId] ?? 0) + $duration;
            $byOperation[$operationId] = ($byOperation[$operationId] ?? 0) + $duration;

            if ($entry->started_at !== null) {
                $starts[] = $entry->started_at->getTimestamp();
            }

            if ($entry->ended_at !== null) {
                $ends[] = $entry->ended_at->getTimestamp();
            }
        }

        $calendarSeconds = $starts === [] || $ends === []
            ? 0
            : max(0, max($ends) - min($starts));

        return [
            'realSeconds' => $realSeconds,
            'byOperator' => $byOperator,
            'byOperation' => $byOperation,
            'calendarSeconds' => $calendarSeconds,
            'entryCount' => $originals->count(),
        ];
    }

    protected function minutesToSeconds(?int $minutes): int
    {
        return max(0, (int) $minutes) * 60;
    }

    protected function percentage(int $realSeconds, int $estimatedSeconds): ?float
    {
        if ($estimatedSeconds <= 0) {
            return null;
        }

        return round(($realSeconds / $estimatedSeconds) * 100, 2);
    }
}
