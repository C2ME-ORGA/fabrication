<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\WorkshopDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkshopDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkshopDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkshopDashboardService::class);
    }

    public function test_the_summary_counts_active_work_orders_operations_timers_and_real_time(): void
    {
        $active = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $draft = WorkOrder::factory()->create();
        $completedWorkOrder = WorkOrder::factory()->completed()->create();

        $pending = $this->operation($active, WorkshopOperationStatus::Pending, 60);
        $this->operation($active, WorkshopOperationStatus::Ready, 60);
        $running = $this->operation($active, WorkshopOperationStatus::InProgress, 60);
        $this->operation($active, WorkshopOperationStatus::Blocked, 60);
        $done = $this->operation($active, WorkshopOperationStatus::Completed, 60);

        $this->operation($draft, WorkshopOperationStatus::InProgress, 60);
        $this->operation($completedWorkOrder, WorkshopOperationStatus::Completed, 60);

        OperationTimeEntry::factory()
            ->forOperation($done)
            ->create(['duration_seconds' => 1800]);

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($running)
            ->forUser(User::factory()->create())
            ->create();

        $summary = $this->service->summary();

        $this->assertSame(1, $summary['released_work_orders']);
        $this->assertSame(1, $summary['operations_by_status'][WorkshopOperationStatus::Pending->value]);
        $this->assertSame(1, $summary['operations_by_status'][WorkshopOperationStatus::Ready->value]);
        $this->assertSame(1, $summary['operations_by_status'][WorkshopOperationStatus::InProgress->value]);
        $this->assertSame(1, $summary['operations_by_status'][WorkshopOperationStatus::Blocked->value]);
        $this->assertSame(1, $summary['operations_by_status'][WorkshopOperationStatus::Completed->value]);
        $this->assertSame(1, $summary['active_timers']);
        $this->assertSame(1800, $summary['total_real_seconds']);
    }

    public function test_supervision_returns_blocked_overdue_in_progress_stale_and_active_data(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $job = $workOrder->job;

        $blocked = $this->operation($workOrder, WorkshopOperationStatus::Blocked, 30);
        $blocked->forceFill([
            'blocked_reason' => 'Attente matière',
            'blocked_at' => now(),
            'blocked_by' => $operator->getKey(),
        ])->save();

        $overdue = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 30);
        OperationTimeEntry::factory()
            ->forOperation($overdue)
            ->create(['duration_seconds' => 3600]);

        $running = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 60);
        OperationTimeEntry::factory()
            ->active()
            ->forOperation($running)
            ->forUser($operator)
            ->create();

        $stale = $this->operation($workOrder, WorkshopOperationStatus::Ready, 30);
        DB::table('work_order_operations')->where('id', $stale->getKey())->update([
            'updated_at' => now()->subDays(2),
        ]);

        $data = $this->service->supervision();

        $this->assertTrue($data['blocked_operations']->contains('id', $blocked->getKey()));
        $this->assertTrue($data['overdue_operations']->contains(
            fn (array $row): bool => $row['operation']->getKey() === $overdue->getKey(),
        ));
        $this->assertTrue($data['work_orders_in_progress']->contains(
            fn (array $row): bool => $row['work_order']->getKey() === $workOrder->getKey(),
        ));
        $this->assertTrue($data['stale_operations']->contains('id', $stale->getKey()));
        $this->assertSame(1, $data['active_operators_count']);
        $this->assertCount(1, $data['active_sessions']);
        $this->assertArrayHasKey($workOrder->getKey(), $data['work_order_summaries']);
        $this->assertSame($job->getKey(), $workOrder->job->getKey());
    }

    public function test_empty_data_returns_zero_indicators(): void
    {
        $summary = $this->service->summary();

        $this->assertSame(0, $summary['released_work_orders']);
        $this->assertSame(0, $summary['active_timers']);
        $this->assertSame(0, $summary['total_real_seconds']);
    }

    protected function operation(
        WorkOrder $workOrder,
        WorkshopOperationStatus $status,
        int $estimatedMinutes,
    ): WorkOrderOperation {
        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'status' => $status,
                'estimated_duration_minutes' => $estimatedMinutes,
            ]);
    }
}
