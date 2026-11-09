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

class WorkOrderRoutingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_assign_a_routing(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $admin = User::factory()->admin()->create();

        $this->assertFalse(Gate::forUser($operator)->allows('manageRouting', $workOrder));
        $this->assertFalse(Gate::forUser($supervisor)->allows('manageRouting', $workOrder));
        $this->assertTrue(Gate::forUser($admin)->allows('manageRouting', $workOrder));
    }

    public function test_routing_cannot_be_managed_after_release(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $this->assertFalse(Gate::forUser($admin)->allows('manageRouting', $workOrder));
    }

    public function test_operations_can_be_modified_before_release_but_not_after(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $operation = WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'status' => WorkshopOperationStatus::Ready,
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('update', $operation));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $operation));
        $this->assertTrue(Gate::forUser($admin)->allows('manageDependencies', $operation));

        $workOrder->forceFill(['status' => WorkOrderStatus::Released])->save();

        $this->assertFalse(Gate::forUser($admin)->allows('update', $operation->fresh()));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $operation->fresh()));
        $this->assertFalse(Gate::forUser($admin)->allows('manageDependencies', $operation->fresh()));
    }

    public function test_only_administrators_can_modify_generated_operations(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->create();
        $operation = WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'status' => WorkshopOperationStatus::Ready,
        ]);

        $this->assertFalse(Gate::forUser($supervisor)->allows('update', $operation));
        $this->assertFalse(Gate::forUser($supervisor)->allows('delete', $operation));
        $this->assertFalse(Gate::forUser($supervisor)->allows('manageDependencies', $operation));
    }

    public function test_a_standard_operator_can_view_but_not_modify_generated_operations(): void
    {
        $operator = User::factory()->create();
        $releasedWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = WorkOrderOperation::factory()
            ->forWorkOrder($releasedWorkOrder)
            ->create([
                'status' => WorkshopOperationStatus::Ready,
            ]);

        $this->assertTrue(Gate::forUser($operator)->allows('viewAny', WorkOrderOperation::class));
        $this->assertTrue(Gate::forUser($operator)->allows('view', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('update', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('delete', $operation));
        $this->assertFalse(Gate::forUser($operator)->allows('manageDependencies', $operation));
    }

    public function test_operations_of_a_non_released_work_order_are_not_exposed_to_operators(): void
    {
        $operator = User::factory()->create();
        $draftWorkOrder = WorkOrder::factory()->create();
        $operation = WorkOrderOperation::factory()
            ->forWorkOrder($draftWorkOrder)
            ->create([
                'status' => WorkshopOperationStatus::Ready,
            ]);

        $this->assertFalse(Gate::forUser($operator)->allows('view', $operation));
    }
}
