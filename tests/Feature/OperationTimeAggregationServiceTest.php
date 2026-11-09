<?php

namespace Tests\Feature;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkOrderStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimeAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationTimeAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OperationTimeAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OperationTimeAggregationService::class);
    }

    public function test_operation_real_time_estimated_time_and_operator_breakdown(): void
    {
        $operation = $this->operation(120);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->entry($operation, $first, 3600, OperationTimeEntryType::Timer);
        $this->entry($operation, $second, 1800, OperationTimeEntryType::Manual);

        $summary = $this->service->forOperation($operation);

        $this->assertSame(5400, $summary->realSeconds);
        $this->assertSame(7200, $summary->estimatedSeconds);
        $this->assertSame(-1800, $summary->varianceSeconds);
        $this->assertSame(75.0, $summary->consumptionPercent);
        $this->assertSame(2, $summary->entryCount);
        $this->assertSame([
            $first->getKey() => 3600,
            $second->getKey() => 1800,
        ], $summary->byOperator);
    }

    public function test_only_the_latest_correction_is_counted_without_double_counting(): void
    {
        $operation = $this->operation(60);
        $operator = User::factory()->create();
        $original = $this->entry($operation, $operator, 3600, OperationTimeEntryType::Timer);

        OperationTimeEntry::factory()
            ->adjustment()
            ->forOperation($operation)
            ->forUser($operator)
            ->create([
                'original_entry_id' => $original->getKey(),
                'duration_seconds' => 1200,
            ]);

        $latest = OperationTimeEntry::factory()
            ->adjustment()
            ->forOperation($operation)
            ->forUser($operator)
            ->create([
                'original_entry_id' => $original->getKey(),
                'duration_seconds' => 600,
            ]);

        $summary = $this->service->forOperation($operation);
        $summaryFromLoadedEntries = $this->service->realSecondsFromEntries(
            $operation->timeEntries()->get(),
        );

        $this->assertSame(600, $summary->realSeconds);
        $this->assertSame(600, $summaryFromLoadedEntries);
        $this->assertSame(1, $summary->entryCount);
        $this->assertSame(3600, $original->fresh()->duration_seconds);
        $this->assertSame(600, $latest->duration_seconds);
    }

    public function test_man_hours_are_distinguished_from_calendar_span_for_simultaneous_work(): void
    {
        $operation = $this->operation(null);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $start = Carbon::parse('2026-11-09 08:00:00');
        $end = Carbon::parse('2026-11-09 10:00:00');

        $this->entry($operation, $first, 7200, OperationTimeEntryType::Timer, $start, $end);
        $this->entry($operation, $second, 7200, OperationTimeEntryType::Timer, $start, $end);

        $summary = $this->service->forOperation($operation);

        $this->assertSame(14400, $summary->realSeconds);
        $this->assertSame(7200, $summary->calendarSeconds);
        $this->assertNull($summary->consumptionPercent);
        $this->assertSame(0, $summary->estimatedSeconds);
    }

    public function test_work_order_aggregates_sum_by_operation_and_by_operator(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $firstOperation = WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'code' => 'OP-A',
            'estimated_duration_minutes' => 60,
        ]);
        $secondOperation = WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'code' => 'OP-B',
            'estimated_duration_minutes' => 30,
        ]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->entry($firstOperation, $first, 3600);
        $this->entry($secondOperation, $first, 600);
        $this->entry($secondOperation, $second, 1200);

        $summary = $this->service->forWorkOrder($workOrder);

        $this->assertSame(5400, $summary->realSeconds);
        $this->assertSame(5400, $summary->estimatedSeconds);
        $this->assertSame(0, $summary->varianceSeconds);
        $this->assertSame(100.0, $summary->consumptionPercent);
        $this->assertSame(3, $summary->entryCount);
        $this->assertSame([
            $firstOperation->getKey() => 3600,
            $secondOperation->getKey() => 1800,
        ], $summary->byOperation);
        $this->assertSame([
            $first->getKey() => 4200,
            $second->getKey() => 1200,
        ], $summary->byOperator);
    }

    public function test_active_sessions_are_not_counted(): void
    {
        $operation = $this->operation(60);
        $operator = User::factory()->create();

        $this->entry($operation, $operator, 3600);

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($operator)
            ->create();

        $this->assertSame(3600, $this->service->forOperation($operation)->realSeconds);
    }

    protected function operation(?int $estimatedMinutes): WorkOrderOperation
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        return WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'estimated_duration_minutes' => $estimatedMinutes,
        ]);
    }

    protected function entry(
        WorkOrderOperation $operation,
        User $operator,
        int $duration,
        OperationTimeEntryType $type = OperationTimeEntryType::Timer,
        ?Carbon $startedAt = null,
        ?Carbon $endedAt = null,
    ): OperationTimeEntry {
        $startedAt ??= Carbon::parse('2026-11-09 08:00:00');
        $endedAt ??= $startedAt->copy()->addSeconds($duration);

        return OperationTimeEntry::factory()
            ->forOperation($operation)
            ->forUser($operator)
            ->create([
                'entry_type' => $type,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
            ]);
    }
}
