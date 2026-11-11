<?php

namespace Tests\Feature;

use App\DTO\ClosureEligibility;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityReleasePhase;
use App\Enums\QualityReleaseReason;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityRelease;
use App\Services\QualityAuditService;
use App\Services\WorkOrderPrintService;
use App\Services\WorkOrderQualityReleaseService;
use App\Services\WorkOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

class WorkOrderQualityReleaseTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    protected User $admin;

    protected User $qualityManager;

    protected User $workshopSupervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->qualityManager = User::factory()->qualityManager()->create();
        $this->workshopSupervisor = User::factory()->workshopSupervisor()->create();
    }

    public function test_closure_records_an_immutable_quality_release_proof(): void
    {
        $workOrder = $this->completedWorkOrder();

        $release = WorkOrderQualityRelease::query()->sole();

        $this->assertSame($workOrder->getKey(), $release->work_order_id);
        $this->assertSame($this->admin->getKey(), $release->released_by);
        $this->assertSame(QualityReleasePhase::Closure, $release->phase);
        $this->assertSame(QualityReleaseReason::Completion, $release->reason);
        $this->assertTrue($release->eligible);
        $this->assertFalse($release->requirements_present);
        $this->assertIsArray($release->eligibility_snapshot);
        $this->assertSame(64, strlen($release->snapshot_hash));
        $this->assertSame('1', $release->rules_version);

        $this->assertDatabaseHas('quality_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => QualityAuditEvent::QualityReleaseRecorded->value,
        ]);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'quality_released',
        ]);
    }

    public function test_quality_release_proof_cannot_be_updated_or_deleted(): void
    {
        $this->completedWorkOrder();
        $release = WorkOrderQualityRelease::query()->sole();

        try {
            $release->eligible = false;
            $release->save();
            $this->fail('La preuve aurait dû être immuable.');
        } catch (RuntimeException) {
            // attendu
        }

        $this->expectException(RuntimeException::class);
        $release->delete();
    }

    public function test_historical_release_requires_an_authorized_quality_profile(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();

        try {
            app(WorkOrderQualityReleaseService::class)
                ->recordHistoricalReview($workOrder, $this->workshopSupervisor, 'Revue atelier.');
            $this->fail('Le responsable atelier ne doit pas créer de preuve qualité.');
        } catch (AuthorizationException) {
            // attendu
        }

        $this->assertDatabaseCount('work_order_quality_releases', 0);

        $this->expectException(AuthorizationException::class);
        app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, User::factory()->create(), 'Revue opérateur.');
    }

    public function test_historical_release_refuses_a_missing_actor(): void
    {
        $this->expectException(ValidationException::class);

        app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($this->historicalCompletedWorkOrder(), null, 'Revue.');
    }

    public function test_historical_release_requires_a_comment(): void
    {
        $this->expectException(ValidationException::class);

        app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($this->historicalCompletedWorkOrder(), $this->qualityManager, '   ');
    }

    public function test_historical_release_only_applies_to_completed_work_orders(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $this->expectException(ValidationException::class);

        app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $this->qualityManager, 'Revue historique.');
    }

    public function test_historical_release_is_refused_when_ineligible(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();

        $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Major,
            'status' => NonConformityStatus::Open,
            'is_blocking' => true,
        ]);

        try {
            app(WorkOrderQualityReleaseService::class)
                ->recordHistoricalReview($workOrder, $this->qualityManager, 'Tentative de libération.');
            $this->fail('La libération aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertDatabaseCount('work_order_quality_releases', 0);
    }

    public function test_multiple_historical_releases_are_appended_without_overwriting(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();

        $first = app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $this->qualityManager, 'Première revue.');
        $second = app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $this->qualityManager, 'Seconde revue.');

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, WorkOrderQualityRelease::query()->count());
        $this->assertSame('Première revue.', $first->fresh()->comment);
        $this->assertSame($second->getKey(), $workOrder->fresh()->latestQualityRelease()?->getKey());
    }

    public function test_historical_work_order_without_proof_has_no_quality_visa(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();

        // Un audit de clôture historique ne doit jamais devenir une preuve.
        app(QualityAuditService::class)->record(
            QualityAuditEvent::ClosureEvaluated,
            QualityAuditSubject::WorkOrder,
            (int) $workOrder->getKey(),
            $workOrder->number,
            $this->admin,
            $workOrder,
            null,
            WorkOrderStatus::Completed->value,
            'Évaluation historique.',
        );

        $data = app(WorkOrderPrintService::class)->build($workOrder, $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['qualite']['status']);
        $this->assertSame('none', $data->visaSnapshot['qualite']['evidence_type']);
    }

    public function test_historical_release_creates_a_persisted_quality_visa(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();

        $release = app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $this->qualityManager, 'Revue documentaire et qualité.');

        $data = app(WorkOrderPrintService::class)->build($workOrder, $this->admin);

        $this->assertSame('effectue', $data->visaSnapshot['qualite']['status']);
        $this->assertSame($this->qualityManager->name, $data->visaSnapshot['qualite']['person_name']);
        $this->assertSame('Responsable qualité', $data->visaSnapshot['qualite']['function_label']);
        $this->assertSame($release->getKey(), $data->visaSnapshot['qualite']['evidence_id']);
        $this->assertSame('QR-'.$release->getKey(), $data->visaSnapshot['qualite']['evidence_reference']);
        $this->assertNotNull($data->visaSnapshot['qualite']['validated_at']);
    }

    public function test_latest_ineligible_proof_removes_the_quality_visa(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();

        $release = app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $this->qualityManager, 'Revue valide.');

        WorkOrderQualityRelease::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'released_by' => $this->qualityManager->getKey(),
            'released_at' => now()->addMinute(),
            'phase' => QualityReleasePhase::HistoricalReview,
            'eligible' => false,
            'requirements_present' => true,
            'eligibility_snapshot' => ['eligible' => false, 'blockers' => []],
            'snapshot_hash' => str_repeat('a', 64),
            'rules_version' => '1',
            'reason' => QualityReleaseReason::ReRelease,
            'comment' => 'Preuve non éligible.',
            'created_at' => now(),
        ]);

        $data = app(WorkOrderPrintService::class)->build($workOrder->fresh(), $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['qualite']['status']);
        $this->assertSame('none', $data->visaSnapshot['qualite']['evidence_type']);
        $this->assertNotNull($release->fresh());
    }

    public function test_requirements_present_is_recorded_when_a_mandatory_control_exists(): void
    {
        $workOrder = $this->historicalCompletedWorkOrder();
        [$control, $point] = $this->createControl($workOrder);
        $this->addValidatedResult($workOrder, $point, true);

        $release = app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $this->qualityManager, 'Revue avec exigences.');

        $this->assertTrue($release->requirements_present);
    }

    public function test_record_for_closure_refuses_a_draft_work_order(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Draft)->create();

        try {
            app(WorkOrderQualityReleaseService::class)
                ->recordForClosure($workOrder, $this->admin);
            $this->fail('Une preuve de clôture ne doit pas être créée sur un brouillon.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertDatabaseCount('work_order_quality_releases', 0);
    }

    public function test_record_for_closure_does_not_accept_a_fabricated_eligibility(): void
    {
        $method = new \ReflectionMethod(
            WorkOrderQualityReleaseService::class,
            'recordForClosure',
        );

        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('workOrder', $parameters[0]->getName());
        $this->assertSame('actor', $parameters[1]->getName());

        foreach ($parameters as $parameter) {
            $this->assertNotSame(
                ClosureEligibility::class,
                $parameter->getType()?->getName(),
            );
        }
    }

    public function test_record_for_closure_refuses_an_ineligible_work_order(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();

        $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Major,
            'status' => NonConformityStatus::Open,
            'is_blocking' => true,
        ]);

        try {
            app(WorkOrderQualityReleaseService::class)
                ->recordForClosure($workOrder, $this->admin);
            $this->fail('La preuve aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertDatabaseCount('work_order_quality_releases', 0);
    }

    public function test_record_for_closure_refuses_an_unauthorized_actor(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();

        try {
            app(WorkOrderQualityReleaseService::class)
                ->recordForClosure($workOrder, $this->workshopSupervisor);
            $this->fail('Le responsable atelier ne doit pas créer de preuve de clôture.');
        } catch (AuthorizationException) {
            // attendu
        }

        $this->assertDatabaseCount('work_order_quality_releases', 0);
    }

    public function test_record_for_closure_refuses_a_missing_actor(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();

        $this->expectException(ValidationException::class);

        app(WorkOrderQualityReleaseService::class)->recordForClosure($workOrder, null);
    }

    public function test_failed_quality_proof_rolls_back_the_completion(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();

        $mock = Mockery::mock(WorkOrderQualityReleaseService::class);
        $mock->shouldReceive('recordForClosure')
            ->once()
            ->andThrow(new RuntimeException('Échec simulé de la preuve.'));
        $this->app->instance(WorkOrderQualityReleaseService::class, $mock);

        $service = app(WorkOrderService::class);

        try {
            $service->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);
            $this->fail('La clôture aurait dû échouer avec la preuve.');
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame(WorkOrderStatus::QualityCheck, $workOrder->fresh()->status);
        $this->assertDatabaseCount('work_order_quality_releases', 0);
    }

    public function test_snapshot_hash_is_independent_of_associative_key_order(): void
    {
        $first = [
            'z' => 1,
            'nested' => ['b' => 2, 'a' => 3],
            'list' => [1, 2, 3],
        ];

        $second = [
            'list' => [1, 2, 3],
            'nested' => ['a' => 3, 'b' => 2],
            'z' => 1,
        ];

        $this->assertSame(
            WorkOrderQualityReleaseService::hashSnapshot($first),
            WorkOrderQualityReleaseService::hashSnapshot($second),
        );
    }

    public function test_snapshot_hash_preserves_indexed_array_order(): void
    {
        $this->assertNotSame(
            WorkOrderQualityReleaseService::hashSnapshot(['control_ids' => [1, 2]]),
            WorkOrderQualityReleaseService::hashSnapshot(['control_ids' => [2, 1]]),
        );
    }

    public function test_snapshot_hash_changes_when_a_value_changes(): void
    {
        $this->assertNotSame(
            WorkOrderQualityReleaseService::hashSnapshot(['eligible' => true]),
            WorkOrderQualityReleaseService::hashSnapshot(['eligible' => false]),
        );
    }

    public function test_snapshot_hash_survives_a_mysql_round_trip(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('La relecture JSON MySQL est nécessaire pour vérifier le hash canonique.');
        }

        $this->completedWorkOrder();

        $release = WorkOrderQualityRelease::query()->sole();

        $this->assertTrue(
            app(WorkOrderQualityReleaseService::class)->verifySnapshot($release->fresh()),
        );
    }

    protected function completedWorkOrder(): WorkOrder
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::QualityCheck)->create();

        return app(WorkOrderService::class)->transitionTo(
            $workOrder,
            WorkOrderStatus::Completed,
            $this->admin,
        );
    }

    protected function historicalCompletedWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()
            ->status(WorkOrderStatus::Completed)
            ->create(['job_id' => Job::factory()->active()->create()->getKey()]);
    }
}
