<?php

namespace Tests\Feature;

use App\Enums\QualityControlStatus;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityControlPlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_record_but_cannot_validate_cancel_or_view_closed_history(): void
    {
        $operator = User::factory()->create();
        $released = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $control = WorkOrderQualityControl::factory()->create(['work_order_id' => $released->getKey()]);

        $this->assertTrue($operator->can('recordResult', $control));
        $this->assertFalse($operator->can('validateResult', $control));
        $this->assertFalse($operator->can('cancel', $control));
        $this->assertTrue($operator->can('view', $control));

        $completed = WorkOrder::factory()->completed()->create();
        $historical = WorkOrderQualityControl::factory()->create([
            'work_order_id' => $completed->getKey(),
            'status' => QualityControlStatus::Passed,
        ]);

        $this->assertFalse($operator->can('view', $historical));
    }

    public function test_a_workshop_supervisor_can_validate_and_cancel_controls(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $released = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $control = WorkOrderQualityControl::factory()->create(['work_order_id' => $released->getKey()]);

        $this->assertTrue($supervisor->can('recordResult', $control));
        $this->assertTrue($supervisor->can('validateResult', $control));
        $this->assertTrue($supervisor->can('cancel', $control));
        $this->assertTrue($supervisor->can('view', $control));
    }

    public function test_a_quality_manager_can_validate_and_manage_templates(): void
    {
        $qualityManager = User::factory()->qualityManager()->create();
        $released = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $control = WorkOrderQualityControl::factory()->create(['work_order_id' => $released->getKey()]);

        $this->assertTrue($qualityManager->can('recordResult', $control));
        $this->assertTrue($qualityManager->can('validateResult', $control));
        $this->assertTrue($qualityManager->can('cancel', $control));
        $this->assertTrue($qualityManager->can('generate', WorkOrderQualityControl::class));
    }

    public function test_an_administrator_has_full_control_access_but_critical_segregation_stays_in_the_service(): void
    {
        $admin = User::factory()->admin()->create();
        $released = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $control = WorkOrderQualityControl::factory()->create(['work_order_id' => $released->getKey()]);

        $this->assertTrue($admin->can('viewAny', WorkOrderQualityControl::class));
        $this->assertTrue($admin->can('recordResult', $control));
        $this->assertTrue($admin->can('validateResult', $control));
        $this->assertTrue($admin->can('cancel', $control));
        $this->assertTrue($admin->can('generate', WorkOrderQualityControl::class));
    }

    public function test_the_operator_and_supervisor_cannot_manage_the_quality_template_catalogue(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $qualityManager = User::factory()->qualityManager()->create();

        $this->assertFalse($operator->can('generate', WorkOrderQualityControl::class));
        $this->assertFalse($supervisor->can('generate', WorkOrderQualityControl::class));
        $this->assertTrue($qualityManager->can('generate', WorkOrderQualityControl::class));
    }
}
