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

class OperationTimePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_use_the_timer_but_not_manage_times(): void
    {
        $operator = User::factory()->create();
        $operation = $this->operation(WorkOrderStatus::Released, WorkshopOperationStatus::InProgress);

        $this->assertTrue(Gate::forUser($operator)->allows('startTimer', $operation));
        $this->assertTrue(Gate::forUser($operator)->allows('stopTimer', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('recordManualTime', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('correctTime', $operation));
    }

    public function test_a_supervisor_and_an_admin_can_record_and_correct_times(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $admin = User::factory()->admin()->create();
        $operation = $this->operation(WorkOrderStatus::Released, WorkshopOperationStatus::InProgress);

        foreach ([$supervisor, $admin] as $manager) {
            $this->assertTrue(Gate::forUser($manager)->allows('recordManualTime', $operation));
            $this->assertTrue(Gate::forUser($manager)->allows('correctTime', $operation));
        }
    }

    public function test_manual_time_is_refused_before_release_or_on_a_cancelled_operation(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = $this->operation(WorkOrderStatus::Draft, WorkshopOperationStatus::InProgress);
        $cancelled = $this->operation(WorkOrderStatus::Released, WorkshopOperationStatus::Cancelled);

        $this->assertFalse(Gate::forUser($admin)->allows('recordManualTime', $draft));
        $this->assertFalse(Gate::forUser($admin)->allows('recordManualTime', $cancelled));
    }

    protected function operation(
        WorkOrderStatus $workOrderStatus,
        WorkshopOperationStatus $operationStatus,
    ): WorkOrderOperation {
        $workOrder = WorkOrder::factory()->status($workOrderStatus)->create();

        return WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'status' => $operationStatus,
        ]);
    }
}
