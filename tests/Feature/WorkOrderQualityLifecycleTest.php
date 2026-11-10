<?php

namespace Tests\Feature;

use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityControlStatus;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Policies\QualityClosurePolicy;
use App\Services\QualityClosureEligibilityService;
use App\Services\QualityControlResultService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class WorkOrderQualityLifecycleTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use BuildsQualityPlan;
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected User $supervisor;

    protected WorkOrderService $workOrders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->workOrders = app(WorkOrderService::class);
    }

    public function test_release_is_refused_when_a_mandatory_quality_plan_control_is_missing(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle obligatoire']]);
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);
        $this->assignRequirement($this->admin, $workOrder, $template);

        $workOrder->fresh()->qualityControls()->sole()->delete();

        try {
            $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::Released, $this->admin);
            $this->fail('La libération aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertSame(WorkOrderStatus::Ready, $workOrder->fresh()->status);
        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::QualityClosureBlocked->value,
        ]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'closure_blocked',
        ]);
    }

    public function test_release_is_allowed_when_the_quality_plan_is_complete_even_before_results(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle obligatoire']]);
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);
        $this->assignRequirement($this->admin, $workOrder, $template);

        $released = $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::Released, $this->admin);

        $this->assertSame(WorkOrderStatus::Released, $released->status);
        $this->assertDatabaseHas('quality_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'closure_evaluated',
        ]);
    }

    public function test_release_is_refused_when_a_blocking_non_conformity_is_open(): void
    {
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);

        $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Major,
            'status' => NonConformityStatus::Open,
            'is_blocking' => true,
        ]);

        try {
            $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::Released, $this->admin);
            $this->fail('La libération aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertSame(WorkOrderStatus::Ready, $workOrder->fresh()->status);
    }

    public function test_work_order_closure_is_refused_while_a_mandatory_control_is_pending(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle obligatoire']]);
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);
        $this->assignRequirement($this->admin, $workOrder, $template);

        $workOrder = $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::Released, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::InProgress, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $this->admin);

        try {
            $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);
            $this->fail('La clôture aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertSame(WorkOrderStatus::QualityCheck, $workOrder->fresh()->status);
        $this->assertDatabaseHas('quality_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'closure_blocked',
        ]);
    }

    public function test_work_order_closure_is_allowed_when_all_mandatory_controls_are_satisfied(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle obligatoire']]);
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);
        $this->assignRequirement($this->admin, $workOrder, $template);

        $workOrder = $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::Released, $this->admin);
        $control = $workOrder->qualityControls()->sole();
        $point = $control->points()->sole();

        $result = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => true],
            $this->operator,
        );
        app(QualityControlResultService::class)->validate(
            $result,
            ['decision' => 'validated'],
            $this->supervisor,
        );

        $this->assertSame(QualityControlStatus::Passed, $control->fresh()->status);

        $workOrder = $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::InProgress, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $this->admin);

        $completed = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);

        $this->assertSame(WorkOrderStatus::Completed, $completed->status);
        $this->assertDatabaseHas('quality_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'closure_evaluated',
        ]);
    }

    public function test_an_work_order_without_quality_requirement_remains_closable(): void
    {
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder->fresh(), WorkOrderStatus::Released, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::InProgress, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $this->admin);
        $completed = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);

        $this->assertSame(WorkOrderStatus::Completed, $completed->status);
    }

    public function test_eligibility_calculation_is_reusable_for_the_policy(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->createControl($workOrder);

        $eligibility = app(QualityClosureEligibilityService::class)->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue(app(QualityClosurePolicy::class)->view($this->admin, $workOrder));
    }
}
