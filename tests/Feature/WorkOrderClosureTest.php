<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimerService;
use App\Services\WorkOrderOperationService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderClosureTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderService $workOrders;

    protected WorkOrderOperationService $operations;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workOrders = app(WorkOrderService::class);
        $this->operations = app(WorkOrderOperationService::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_closing_a_work_order_is_refused_while_an_operation_is_in_progress(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $this->assertTransitionFails($workOrder, WorkOrderStatus::Completed, 'operations');

        $this->assertSame(WorkOrderStatus::QualityCheck, $workOrder->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);
    }

    public function test_cancelling_a_work_order_is_refused_while_an_operation_is_in_progress(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $this->assertTransitionFails($workOrder, WorkOrderStatus::Cancelled, 'operations');

        $this->assertSame(WorkOrderStatus::Released, $workOrder->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);
    }

    public function test_after_explicit_operation_cancellation_the_work_order_can_be_cancelled(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $cancelledOperation = $this->operations->cancel($operation, $this->admin, 'Opération abandonnée');

        $this->assertSame(WorkshopOperationStatus::Cancelled, $cancelledOperation->status);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::Cancelled->value,
            'user_id' => $this->admin->getKey(),
            'comment' => 'Opération abandonnée',
        ]);

        $cancelledWorkOrder = $this->workOrders->transitionTo(
            $workOrder->fresh(),
            WorkOrderStatus::Cancelled,
            $this->admin,
            'OF annulé après annulation des opérations',
        );

        $this->assertSame(WorkOrderStatus::Cancelled, $cancelledWorkOrder->status);
    }

    public function test_after_explicit_operation_cancellation_the_work_order_can_be_completed(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $this->operations->cancel($operation, $this->admin, 'Opération abandonnée');

        $completed = $this->workOrders->transitionTo(
            $workOrder->fresh(),
            WorkOrderStatus::Completed,
            $this->admin,
        );

        $this->assertSame(WorkOrderStatus::Completed, $completed->status);
    }

    public function test_every_in_progress_operation_must_be_cancelled_explicitly(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $first = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-ONE');
        $second = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-TWO');

        $this->operations->cancel($first, $this->admin, 'Première opération annulée');

        $this->assertTransitionFails($workOrder, WorkOrderStatus::Cancelled, 'operations');

        $this->operations->cancel($second, $this->admin, 'Seconde opération annulée');

        $cancelled = $this->workOrders->transitionTo(
            $workOrder->fresh(),
            WorkOrderStatus::Cancelled,
            $this->admin,
        );

        $this->assertSame(WorkOrderStatus::Cancelled, $cancelled->status);
        $this->assertSame(WorkshopOperationStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::Cancelled, $second->fresh()->status);
    }

    public function test_completed_operations_are_never_cancelled_implicitly(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $completed = $this->operation($workOrder, WorkshopOperationStatus::Completed, 'OP-DONE');
        $inProgress = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-RUNNING');

        $this->assertTransitionFails($workOrder, WorkOrderStatus::Cancelled, 'operations');

        $this->assertSame(WorkshopOperationStatus::Completed, $completed->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::InProgress, $inProgress->fresh()->status);
        $this->assertDatabaseMissing('work_order_operation_audit_logs', [
            'work_order_operation_id' => $completed->getKey(),
            'event' => WorkshopOperationAuditEvent::Cancelled->value,
        ]);

        $this->operations->cancel($inProgress, $this->admin, 'Opération en cours annulée');

        $cancelled = $this->workOrders->transitionTo(
            $workOrder->fresh(),
            WorkOrderStatus::Cancelled,
            $this->admin,
        );

        $this->assertSame(WorkOrderStatus::Cancelled, $cancelled->status);
        $this->assertSame(WorkshopOperationStatus::Completed, $completed->fresh()->status);
    }

    public function test_an_active_timer_blocks_both_closure_and_cancellation_without_side_effects(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $entry = app(OperationTimerService::class)->start($operation, $operator);

        $this->assertTransitionFails($workOrder, WorkOrderStatus::Cancelled, 'time_entries');

        $workOrder->forceFill(['status' => WorkOrderStatus::QualityCheck])->save();

        $this->assertTransitionFails($workOrder->fresh(), WorkOrderStatus::Completed, 'time_entries');

        $this->assertSame(WorkOrderStatus::QualityCheck, $workOrder->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);
        $this->assertNull($entry->fresh()->ended_at);
        $this->assertSame(1, OperationTimeEntry::query()->whereNull('ended_at')->count());
    }

    public function test_cancelling_an_in_progress_operation_keeps_closed_time_entries_intact(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $closedEntry = OperationTimeEntry::factory()
            ->forOperation($operation)
            ->forUser(User::factory()->create())
            ->create(['duration_seconds' => 600]);

        $this->operations->cancel($operation, $this->admin, 'Opération abandonnée');

        $this->assertSame(WorkshopOperationStatus::Cancelled, $operation->fresh()->status);
        $this->assertSame(600, $closedEntry->fresh()->duration_seconds);
        $this->assertNotNull($closedEntry->fresh()->ended_at);
        $this->assertDatabaseHas('operation_time_entries', [
            'id' => $closedEntry->getKey(),
            'duration_seconds' => 600,
        ]);
    }

    protected function operation(
        WorkOrder $workOrder,
        WorkshopOperationStatus $status,
        string $code = 'OP-TEST',
    ): WorkOrderOperation {
        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'code' => $code,
                'status' => $status,
            ]);
    }

    protected function assertTransitionFails(
        WorkOrder $workOrder,
        WorkOrderStatus $target,
        string $field,
    ): void {
        try {
            $this->workOrders->transitionTo($workOrder, $target, $this->admin);

            $this->fail("La transition vers {$target->value} aurait dû être refusée.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
