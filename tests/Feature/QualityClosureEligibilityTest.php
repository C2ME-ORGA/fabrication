<?php

namespace Tests\Feature;

use App\Enums\ClosureBlockerCode;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityControlStatus;
use App\Enums\QualityDeviationStatus;
use App\Enums\WorkOrderStatus;
use App\Models\QualityAuditLog;
use App\Models\QualityControlResultValidation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityClosureEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class QualityClosureEligibilityTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use BuildsQualityPlan;
    use RefreshDatabase;

    protected User $admin;

    protected QualityClosureEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->service = app(QualityClosureEligibilityService::class);
    }

    public function test_an_work_order_without_mandatory_control_is_eligible(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertTrue($eligibility->eligible);
        $this->assertSame([], $eligibility->blockers);
    }

    public function test_a_pending_mandatory_control_blocks_closure(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->createControl($workOrder);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::MissingMandatoryControl));
    }

    public function test_a_validated_conforming_result_makes_the_control_eligible(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [, $point] = $this->createControl($workOrder);
        $this->addValidatedResult($workOrder, $point, true);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertTrue($eligibility->eligible, $eligibility->message());
    }

    public function test_an_unvalidated_result_blocks_closure(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [, $point] = $this->createControl($workOrder);
        $this->addValidatedResult($workOrder, $point, true, validations: 0);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::UnvalidatedMandatoryResult));
    }

    public function test_a_critical_point_requires_two_distinct_validations(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [, $point] = $this->createControl($workOrder, pointAttributes: [
            'is_critical' => true,
        ]);
        $result = $this->addValidatedResult($workOrder, $point, true);

        $this->assertFalse($this->service->evaluate($workOrder)->eligible);

        QualityControlResultValidation::factory()->create([
            'quality_control_result_id' => $result->getKey(),
            'decision' => 'validated',
            'validated_by' => User::factory()->create()->getKey(),
            'validated_at' => now(),
        ]);

        $this->assertTrue($this->service->evaluate($workOrder)->eligible);
    }

    public function test_a_failed_mandatory_control_blocks_without_deviation(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control, $point] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => true,
        ]);
        $this->addValidatedResult($workOrder, $point, false);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::NonConformingMandatoryControl));
        $this->assertSame([(int) $control->getKey()], $eligibility->controlIds);
    }

    public function test_an_active_deviation_lifts_only_its_exact_control_target(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        [$firstControl, $firstPoint] = $this->createControl($workOrder, ['status' => QualityControlStatus::Failed], ['allow_deviation' => true]);
        [$secondControl, $secondPoint] = $this->createControl($workOrder, ['status' => QualityControlStatus::Failed], ['allow_deviation' => true]);

        $this->addValidatedResult($workOrder, $firstPoint, false);
        $this->addValidatedResult($workOrder, $secondPoint, false);

        $deviation = $this->addActiveDeviation($workOrder, [
            'work_order_quality_control_id' => $firstControl->getKey(),
        ], ['scope' => 'control']);

        $partial = $this->service->evaluate($workOrder);

        $this->assertFalse($partial->eligible);
        $this->assertContains((int) $deviation->getKey(), $partial->deviationIds);
        $this->assertTrue($this->hasBlockerForControl($partial, (int) $secondControl->getKey()));

        $this->addActiveDeviation($workOrder, [
            'work_order_quality_control_id' => $secondControl->getKey(),
        ], ['scope' => 'control']);

        $this->assertTrue($this->service->evaluate($workOrder)->eligible);
    }

    public function test_an_expired_deviation_does_not_lift_a_blocker(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [, $point] = $this->createControl($workOrder, ['status' => QualityControlStatus::Failed], ['allow_deviation' => true]);
        $this->addValidatedResult($workOrder, $point, false);

        $deviation = $this->addActiveDeviation($workOrder, [
            'work_order_quality_control_point_id' => $point->getKey(),
        ], [
            'scope' => 'control_point',
            'expires_at' => now()->subMinute(),
        ]);

        $eligibility = $this->service->evaluate($workOrder);
        $secondEvaluation = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertFalse($secondEvaluation->eligible);
        $this->assertSame(QualityDeviationStatus::Expired, $deviation->fresh()->status);
        $this->assertFalse($deviation->fresh()->isActive());
        $this->assertSame(1, QualityAuditLog::query()
            ->where('subject_type', 'deviation')
            ->where('subject_id', $deviation->getKey())
            ->where('event', 'deviation_expired')
            ->count());
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'deviation',
            'subject_id' => $deviation->getKey(),
            'event' => 'deviation_expired',
        ]);
    }

    public function test_a_revoked_deviation_does_not_lift_a_blocker(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [, $point] = $this->createControl($workOrder, ['status' => QualityControlStatus::Failed], ['allow_deviation' => true]);
        $this->addValidatedResult($workOrder, $point, false);

        $deviation = $this->addActiveDeviation($workOrder, [
            'work_order_quality_control_point_id' => $point->getKey(),
        ], ['scope' => 'control_point']);

        $deviation->forceFill(['status' => QualityDeviationStatus::Revoked->value])->save();

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertSame([], $eligibility->deviationIds);
    }

    public function test_an_open_blocking_non_conformity_blocks_and_a_closed_one_does_not(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $nonConformity = $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Major,
            'status' => NonConformityStatus::Open,
            'is_blocking' => true,
        ]);

        $blocked = $this->service->evaluate($workOrder);

        $this->assertFalse($blocked->eligible);
        $this->assertTrue($this->hasBlocker($blocked, ClosureBlockerCode::OpenBlockingNonConformity));

        $nonConformity->forceFill([
            'status' => NonConformityStatus::Closed->value,
            'closed_at' => now(),
        ])->save();

        $this->assertTrue($this->service->evaluate($workOrder)->eligible);
    }

    public function test_a_minor_non_blocking_non_conformity_does_not_block(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Minor,
            'status' => NonConformityStatus::Open,
            'is_blocking' => false,
        ]);

        $this->assertTrue($this->service->evaluate($workOrder)->eligible);
    }

    public function test_a_critical_open_non_conformity_blocks(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Critical,
            'status' => NonConformityStatus::Open,
            'is_blocking' => true,
        ]);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::OpenBlockingNonConformity));
    }

    public function test_a_pending_verification_non_conformity_blocks_even_with_deviation(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $nonConformity = $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Major,
            'status' => NonConformityStatus::PendingVerification,
            'is_blocking' => true,
            'verification_required' => true,
            'treated_at' => now(),
        ]);

        $this->addActiveDeviation($workOrder, [
            'non_conformity_id' => $nonConformity->getKey(),
        ], ['scope' => 'non_conformity']);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::PendingVerificationNonConformity));
        $this->assertSame([], $eligibility->deviationIds);
    }

    public function test_a_final_documentary_control_must_pass_before_closure(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->createControl($workOrder, [
            'control_type' => 'final',
            'scope' => 'work_order',
            'status' => QualityControlStatus::Pending,
        ], [
            'is_mandatory' => true,
        ]);

        $eligibility = $this->service->evaluate($workOrder);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::MissingFinalDocumentaryControl));
    }

    public function test_release_phase_checks_plan_integrity_but_not_execution_results(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Ready)->create();
        $this->createControl($workOrder);

        $release = $this->service->evaluate($workOrder, QualityClosureEligibilityService::PHASE_RELEASE);
        $closure = $this->service->evaluate($workOrder, QualityClosureEligibilityService::PHASE_CLOSURE);

        $this->assertTrue($release->eligible);
        $this->assertFalse($closure->eligible);
    }

    public function test_a_mandatory_requirement_without_generated_control_blocks_release(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle obligatoire']]);
        $workOrder = $this->readyWorkOrderWithBeApproval($this->admin);
        $this->assignRequirement($this->admin, $workOrder, $template);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $control->delete();

        $eligibility = $this->service->evaluate($workOrder->fresh(), QualityClosureEligibilityService::PHASE_RELEASE);

        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($this->hasBlocker($eligibility, ClosureBlockerCode::MissingMandatoryControl));
    }

    public function test_an_optional_control_failure_does_not_block(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [, $point] = $this->createControl($workOrder, [
            'is_mandatory' => false,
            'status' => QualityControlStatus::Failed,
        ]);
        $this->addValidatedResult($workOrder, $point, false);

        $this->assertTrue($this->service->evaluate($workOrder)->eligible);
    }

    protected function hasBlocker($eligibility, ClosureBlockerCode $code): bool
    {
        return collect($eligibility->blockers)->contains(
            fn ($blocker): bool => $blocker->code === $code,
        );
    }

    protected function hasBlockerForControl($eligibility, int $controlId): bool
    {
        return collect($eligibility->blockers)->contains(
            fn ($blocker): bool => $blocker->controlId === $controlId,
        );
    }
}
