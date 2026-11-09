<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class WorkOrderOperationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_execute_but_not_unblock_or_cancel(): void
    {
        $operator = User::factory()->create();
        $operation = $this->operationForReleasedWorkOrder(WorkshopOperationStatus::InProgress);

        $this->assertTrue(Gate::forUser($operator)->allows('start', $operation));
        $this->assertTrue(Gate::forUser($operator)->allows('pause', $operation));
        $this->assertTrue(Gate::forUser($operator)->allows('resume', $operation));
        $this->assertTrue(Gate::forUser($operator)->allows('block', $operation));
        $this->assertTrue(Gate::forUser($operator)->allows('complete', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('unblock', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('cancel', $operation));
    }

    public function test_a_workshop_supervisor_and_an_admin_can_unblock_and_cancel(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $admin = User::factory()->admin()->create();
        $operation = $this->operationForReleasedWorkOrder(WorkshopOperationStatus::Blocked);

        foreach ([$supervisor, $admin] as $manager) {
            $this->assertTrue(Gate::forUser($manager)->allows('unblock', $operation));
            $this->assertTrue(Gate::forUser($manager)->allows('cancel', $operation));
            $this->assertTrue(Gate::forUser($manager)->allows('resume', $operation));
        }
    }

    public function test_an_operator_cannot_resume_a_blocked_operation(): void
    {
        $operator = User::factory()->create();
        $operation = $this->operationForReleasedWorkOrder(WorkshopOperationStatus::Blocked);

        $this->assertFalse(Gate::forUser($operator)->allows('resume', $operation));
    }

    public function test_operations_cannot_be_executed_before_release_or_after_closure(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([WorkOrderStatus::Draft, WorkOrderStatus::Ready, WorkOrderStatus::Completed, WorkOrderStatus::Cancelled] as $status) {
            $workOrder = WorkOrder::factory()->status($status)->create();
            $operation = WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
                'status' => WorkshopOperationStatus::Ready,
            ]);

            $this->assertFalse(Gate::forUser($admin)->allows('start', $operation));
            $this->assertFalse(Gate::forUser($admin)->allows('complete', $operation));
        }
    }

    protected function operationForReleasedWorkOrder(WorkshopOperationStatus $status): WorkOrderOperation
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        return WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'status' => $status,
        ]);
    }
}
