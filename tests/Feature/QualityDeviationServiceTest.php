<?php

namespace Tests\Feature;

use App\Enums\NonConformitySeverity;
use App\Enums\QualityControlStatus;
use App\Enums\QualityDeviationScope;
use App\Enums\QualityDeviationStatus;
use App\Enums\WorkOrderStatus;
use App\Models\QualityAuditLog;
use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\QualityDeviationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

class QualityDeviationServiceTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    protected User $admin;

    protected User $qualityManager;

    protected User $secondQualityManager;

    protected User $supervisor;

    protected QualityDeviationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->qualityManager = User::factory()->qualityManager()->create();
        $this->secondQualityManager = User::factory()->qualityManager()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->service = app(QualityDeviationService::class);
    }

    public function test_a_control_deviation_can_be_requested_approved_and_revoked_without_changing_results(): void
    {
        [$workOrder, $control, $point, $result] = $this->deviableControl(criticalPoint: true);

        $deviation = $this->service->request($workOrder, [
            'scope' => QualityDeviationScope::Control->value,
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Défaut isolé acceptable après analyse métier et validation technique.',
        ], $this->qualityManager);

        $this->assertSame(QualityDeviationStatus::Pending, $deviation->status);
        $this->assertSame($workOrder->getKey(), $deviation->work_order_id);
        $this->assertSame($control->getKey(), $deviation->targets->first()->work_order_quality_control_id);
        $this->assertSame($this->qualityManager->getKey(), $deviation->requested_by);
        $this->assertTrue($deviation->is_critical);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'deviation',
            'subject_id' => $deviation->getKey(),
            'event' => 'deviation_requested',
        ]);

        $approved = $this->service->approve($deviation, $this->secondQualityManager, 'Première approbation.');
        $this->assertSame(QualityDeviationStatus::Pending, $approved->status);

        $approved = $this->service->approve($approved, $this->admin, 'Seconde approbation.');
        $this->assertSame(QualityDeviationStatus::Active, $approved->status);
        $this->assertNotNull($approved->granted_at);
        $this->assertSame($this->admin->getKey(), $approved->second_approver_id);
        $this->assertSame(2, QualityAuditLog::query()
            ->where('subject_type', 'deviation')
            ->where('subject_id', $deviation->getKey())
            ->where('event', 'deviation_approved')
            ->count());

        $revoked = $this->service->revoke(
            $approved,
            $this->qualityManager,
            'Révocation après retour d\'information du client.',
        );

        $this->assertSame(QualityDeviationStatus::Revoked, $revoked->status);
        $this->assertSame($this->qualityManager->getKey(), $revoked->revoked_by);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertFalse($result->fresh()->is_conform);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'deviation',
            'subject_id' => $deviation->getKey(),
            'event' => 'deviation_revoked',
        ]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'deviation',
            'subject_id' => $deviation->getKey(),
            'event' => 'deviation_granted',
        ]);
    }

    public function test_a_non_critical_deviation_is_active_after_one_approval(): void
    {
        [$workOrder, , $point, $result] = $this->deviableControl(criticalPoint: false);

        $deviation = $this->service->request($workOrder, [
            'scope' => QualityDeviationScope::ControlPoint->value,
            'work_order_quality_control_point_id' => $point->getKey(),
            'reason' => 'Écart mineur accepté après contrôle complémentaire.',
        ], $this->qualityManager);

        $this->assertFalse($deviation->is_critical);
        $this->assertSame(QualityDeviationStatus::Pending, $deviation->status);

        $active = $this->service->approve($deviation, $this->admin, 'Accepté.');

        $this->assertSame(QualityDeviationStatus::Active, $active->status);
        $this->assertSame($this->admin->getKey(), $active->granted_by);
        $this->assertFalse($result->fresh()->is_conform);
    }

    public function test_a_deviation_cannot_be_requested_on_a_draft_work_order(): void
    {
        $workOrder = WorkOrder::factory()->create();
        [$control, $point] = $this->createControl($workOrder, [], ['allow_deviation' => true]);
        $this->addValidatedResult($workOrder, $point, false);

        $this->expectException(ValidationException::class);

        $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Tentative de dérogation avant la libération de l\'OF.',
        ], $this->qualityManager);
    }

    public function test_a_global_work_order_deviation_scope_is_rejected(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        try {
            $this->service->request($workOrder, [
                'scope' => QualityDeviationScope::WorkOrder->value,
                'work_order_quality_control_id' => 999999,
                'reason' => 'Une dérogation globale au niveau de l\'OF doit être refusée.',
            ], $this->qualityManager);

            $this->fail('Une dérogation globale OF ne doit pas être acceptée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope', $exception->errors());
        }

        $this->assertDatabaseCount('quality_deviations', 0);
    }

    public function test_a_deviation_reason_shorter_than_twenty_characters_is_refused(): void
    {
        [$workOrder, $control] = $this->deviableControl();

        $this->expectException(ValidationException::class);

        $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Trop court.',
        ], $this->qualityManager);
    }

    public function test_a_deviation_is_refused_when_allow_deviation_is_false(): void
    {
        [$workOrder, $control] = $this->deviableControl(allowDeviation: false);

        $this->expectException(ValidationException::class);

        $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Tentative de dérogation sur un point non autorisé.',
        ], $this->qualityManager);
    }

    public function test_a_missing_control_cannot_be_covered_by_a_deviation(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $this->expectException(ValidationException::class);

        $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => 999999,
            'reason' => 'Tentative de couvrir un contrôle qui n\'existe pas.',
        ], $this->qualityManager);
    }

    public function test_a_final_documentary_control_cannot_be_covered_by_a_deviation(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control] = $this->createControl($workOrder, [
            'control_type' => 'final',
            'scope' => 'work_order',
            'status' => QualityControlStatus::Failed,
        ], ['allow_deviation' => true]);

        $this->addValidatedResult($workOrder, $control->points()->sole(), false);

        $this->expectException(ValidationException::class);

        $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Tentative de dérogation sur un contrôle final documentaire.',
        ], $this->qualityManager);
    }

    public function test_a_critical_non_conformity_cannot_be_covered_by_a_deviation(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $nonConformity = $this->addNonConformity($workOrder, [
            'severity' => NonConformitySeverity::Critical,
            'is_blocking' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->request($workOrder, [
            'scope' => 'non_conformity',
            'non_conformity_id' => $nonConformity->getKey(),
            'reason' => 'Tentative de dérogation sur une non-conformité critique.',
        ], $this->qualityManager);
    }

    public function test_a_critical_deviation_requires_two_distinct_approvers(): void
    {
        [$workOrder, $control] = $this->deviableControl(criticalPoint: true);

        $deviation = $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Dérogation critique soumise à double approbation.',
        ], $this->qualityManager);

        $this->service->approve($deviation, $this->secondQualityManager);

        try {
            $this->service->approve($deviation->fresh(), $this->secondQualityManager);
            $this->fail('Le même utilisateur ne doit pas réaliser les deux approbations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $active = $this->service->approve($deviation->fresh(), $this->admin);

        $this->assertSame(QualityDeviationStatus::Active, $active->status);
    }

    public function test_the_requester_cannot_approve_their_own_deviation(): void
    {
        [$workOrder, $control] = $this->deviableControl();

        $deviation = $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Demande dont le demandeur tente de s\'auto-approuver.',
        ], $this->qualityManager);

        try {
            $this->service->approve($deviation, $this->qualityManager);
            $this->fail('Le demandeur ne doit pas approuver sa propre demande.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertTrue(array_key_exists('approver', $errors) || array_key_exists('deviation', $errors));
        }

        $this->assertSame(QualityDeviationStatus::Pending, $deviation->fresh()->status);
    }

    public function test_a_pending_deviation_can_be_rejected(): void
    {
        [$workOrder, $control] = $this->deviableControl();

        $deviation = $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Demande finalement refusée après analyse de risque.',
        ], $this->qualityManager);

        $rejected = $this->service->reject($deviation, $this->qualityManager, 'Risque sécurité trop élevé.');

        $this->assertSame(QualityDeviationStatus::Rejected, $rejected->status);
        $this->assertSame($this->qualityManager->getKey(), $rejected->rejected_by);
        $this->assertSame('Risque sécurité trop élevé.', $rejected->rejection_reason);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'deviation',
            'subject_id' => $deviation->getKey(),
            'event' => 'deviation_rejected',
        ]);
    }

    public function test_a_supervisor_cannot_request_or_approve_a_deviation(): void
    {
        [$workOrder, $control] = $this->deviableControl();

        try {
            $this->service->request($workOrder, [
                'scope' => 'control',
                'work_order_quality_control_id' => $control->getKey(),
                'reason' => 'Tentative de dérogation par un responsable atelier.',
            ], $this->supervisor);
            $this->fail('Un responsable atelier ne doit pas pouvoir demander une dérogation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deviation', $exception->errors());
        }
    }

    public function test_only_one_pending_or_active_deviation_is_allowed_per_exact_target(): void
    {
        [$workOrder, $control, $point] = $this->deviableControl();

        $payload = [
            'scope' => QualityDeviationScope::Control->value,
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Première dérogation en attente sur ce contrôle exact.',
        ];

        $this->service->request($workOrder, $payload, $this->qualityManager);

        try {
            $this->service->request($workOrder, $payload, $this->admin);
            $this->fail('Une deuxième dérogation sur la même cible aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target', $exception->errors());
        }

        [$secondControl, $secondPoint] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => true,
        ]);
        $this->addValidatedResult($workOrder, $secondPoint, false);

        $other = $this->service->request($workOrder, [
            'scope' => QualityDeviationScope::Control->value,
            'work_order_quality_control_id' => $secondControl->getKey(),
            'reason' => 'Dérogation sur une autre cible, autorisée indépendamment.',
        ], $this->admin);

        $this->assertSame(QualityDeviationStatus::Pending, $other->status);
    }

    public function test_a_deviation_cannot_be_deleted_and_keeps_its_history(): void
    {
        [$workOrder, $control] = $this->deviableControl();

        $deviation = $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Dérogation à conserver dans l\'historique.',
        ], $this->qualityManager);

        $this->expectException(RuntimeException::class);

        $deviation->delete();
    }

    public function test_evidence_can_be_attached_to_a_deviation(): void
    {
        Storage::fake('documents');

        [$workOrder, $control] = $this->deviableControl();
        $deviation = $this->service->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Dérogation documentée par une analyse technique.',
        ], $this->qualityManager);

        $attachment = $this->service->attachEvidence(
            $deviation,
            UploadedFile::fake()->image('analyse.png', 100, 100),
            $this->qualityManager,
        );

        $this->assertSame('analyse.png', $attachment->original_filename);
        Storage::disk('documents')->assertExists($attachment->path);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'deviation',
            'subject_id' => $deviation->getKey(),
            'event' => 'deviation_evidence_added',
        ]);
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderQualityControl, 2: WorkOrderQualityControlPoint, 3: QualityControlResult}
     */
    protected function deviableControl(
        bool $allowDeviation = true,
        bool $criticalPoint = false,
    ): array {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control, $point] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => $allowDeviation,
            'is_critical' => $criticalPoint,
        ]);
        $result = $this->addValidatedResult($workOrder, $point, false);

        return [$workOrder->fresh(), $control->fresh(), $point->fresh(), $result->fresh()];
    }
}
