<?php

namespace Tests\Feature;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformityOrigin;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\WorkOrderStatus;
use App\Models\NonConformity;
use App\Models\QualityAuditLog;
use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\NonConformityLifecycleService;
use App\Services\NonConformityService;
use App\Services\QualityControlResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class NonConformityServiceTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected User $supervisor;

    protected User $qualityManager;

    protected NonConformityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->qualityManager = User::factory()->qualityManager()->create();
        $this->service = app(NonConformityService::class);
    }

    public function test_a_non_conformity_can_be_created_from_a_non_conforming_result(): void
    {
        [$workOrder, $control, $point, $result] = $this->nonConformingResult();

        $nonConformity = $this->service->createFromResult($result, [
            'description' => 'Longueur hors tolérance sur le repère contrôlé.',
            'criterion' => 'Longueur 6000 ± 5 mm',
            'severity' => NonConformitySeverity::Major->value,
            'category' => NonConformityCategory::Dimensional->value,
            'detected_at' => now()->subMinute(),
            'immediate_action' => 'Mise en quarantaine du lot.',
        ], $this->operator);

        $this->assertStringStartsWith('NC-', $nonConformity->reference);
        $this->assertSame(NonConformityOrigin::ControlResult, $nonConformity->origin);
        $this->assertSame(NonConformityStatus::Open, $nonConformity->status);
        $this->assertSame($workOrder->getKey(), $nonConformity->work_order_id);
        $this->assertSame($workOrder->number, $nonConformity->work_order_number);
        $this->assertSame($control->getKey(), $nonConformity->work_order_quality_control_id);
        $this->assertSame($result->getKey(), $nonConformity->quality_control_result_id);
        $this->assertSame($control->work_order_operation_id, $nonConformity->work_order_operation_id);
        $this->assertSame('Longueur 6000 ± 5 mm', $nonConformity->criterion);
        $this->assertTrue($nonConformity->is_blocking);
        $this->assertTrue($nonConformity->verification_required);
        $this->assertFalse($nonConformity->counter_control_required);
        $this->assertSame($this->operator->getKey(), $nonConformity->detected_by);
        $this->assertSame(1, $nonConformity->statusHistories()->count());
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_created',
        ]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'severity_policy_applied',
        ]);

        // Le résultat source reste strictement inchangé.
        $this->assertFalse($result->fresh()->is_conform);
        $this->assertSame(1, $point->results()->count());
    }

    public function test_a_result_cannot_declare_two_non_conformities(): void
    {
        [, , , $result] = $this->nonConformingResult();

        $payload = [
            'description' => 'Première non-conformité sur ce résultat.',
            'severity' => NonConformitySeverity::Minor->value,
            'category' => NonConformityCategory::Other->value,
        ];

        $this->service->createFromResult($result, $payload, $this->operator);

        try {
            $this->service->createFromResult($result, $payload, $this->operator);
            $this->fail('Un doublon de NC aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('result', $exception->errors());
        }

        $this->assertSame(1, NonConformity::query()->where('quality_control_result_id', $result->getKey())->count());
    }

    public function test_a_non_conformity_can_be_created_from_a_failed_control_without_a_specific_result(): void
    {
        [$workOrder, $control] = $this->nonConformingResult(validate: true);

        $this->assertSame('failed', $control->fresh()->status->value);

        $nonConformity = $this->service->createFromControl($control, [
            'description' => 'Contrôle process non conforme sans résultat individuel retenu.',
            'severity' => NonConformitySeverity::Minor->value,
            'category' => NonConformityCategory::Process->value,
        ], $this->operator);

        $this->assertSame($control->getKey(), $nonConformity->work_order_quality_control_id);
        $this->assertNull($nonConformity->quality_control_result_id);
        $this->assertSame(NonConformityOrigin::Operation, $nonConformity->origin);
        $this->assertSame($workOrder->getKey(), $nonConformity->work_order_id);
    }

    public function test_a_manual_non_conformity_can_be_attached_to_an_operation_and_an_item(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();
        $operation = WorkOrderOperation::factory()->forWorkOrder($workOrder)->create();

        $nonConformity = $this->service->createManual($workOrder, [
            'work_order_operation_id' => $operation->getKey(),
            'work_order_item_id' => $item->getKey(),
            'description' => 'Défaut visuel constaté en cours de fabrication.',
            'severity' => NonConformitySeverity::Minor->value,
            'category' => NonConformityCategory::Visual->value,
        ], $this->operator);

        $this->assertSame($operation->getKey(), $nonConformity->work_order_operation_id);
        $this->assertSame($item->getKey(), $nonConformity->work_order_item_id);
        $this->assertSame(NonConformityOrigin::Item, $nonConformity->origin);
        $this->assertSame($workOrder->number, $nonConformity->work_order_number);
    }

    public function test_a_manual_non_conformity_is_refused_on_an_out_of_scope_work_order_even_on_direct_service_call(): void
    {
        $completed = WorkOrder::factory()->completed()->create();

        try {
            $this->service->createManual($completed, [
                'description' => 'Tentative de NC sur un OF hors périmètre.',
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Other->value,
            ], $this->operator);

            $this->fail('Un opérateur ne doit pas déclarer de NC sur un OF gelé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order', $exception->errors());
        }

        $this->assertDatabaseCount('non_conformities', 0);
    }

    public function test_a_non_conformity_from_a_result_is_refused_on_an_out_of_scope_work_order(): void
    {
        [$workOrder, , , $result] = $this->nonConformingResult();

        $workOrder->forceFill([
            'status' => WorkOrderStatus::Completed,
            'actual_end_at' => now(),
        ])->save();

        try {
            $this->service->createFromResult($result->fresh(), [
                'description' => 'Tentative de NC depuis un résultat d\'OF gelé.',
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Other->value,
            ], $this->operator);

            $this->fail('Un opérateur ne doit pas déclarer de NC depuis un résultat d\'OF gelé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order', $exception->errors());
        }

        $this->assertDatabaseCount('non_conformities', 0);
    }

    public function test_a_non_conformity_from_a_control_is_refused_on_an_out_of_scope_work_order(): void
    {
        [$workOrder, $control] = $this->nonConformingResult(validate: true);

        $workOrder->forceFill([
            'status' => WorkOrderStatus::Completed,
            'actual_end_at' => now(),
        ])->save();

        try {
            $this->service->createFromControl($control->fresh(), [
                'description' => 'Tentative de NC depuis un contrôle d\'OF gelé.',
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Other->value,
            ], $this->operator);

            $this->fail('Un opérateur ne doit pas déclarer de NC depuis un contrôle d\'OF gelé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order', $exception->errors());
        }

        $this->assertDatabaseCount('non_conformities', 0);
    }

    public function test_a_quality_manager_can_create_a_non_conformity_from_a_result_on_a_frozen_work_order(): void
    {
        [$workOrder, , , $result] = $this->nonConformingResult();

        $workOrder->forceFill([
            'status' => WorkOrderStatus::Completed,
            'actual_end_at' => now(),
        ])->save();

        $nonConformity = $this->service->createFromResult($result->fresh(), [
            'description' => 'NC déclarée après clôture par le responsable qualité.',
            'severity' => NonConformitySeverity::Minor->value,
            'category' => NonConformityCategory::Other->value,
        ], $this->qualityManager);

        $this->assertSame($workOrder->getKey(), $nonConformity->work_order_id);
    }

    public function test_assignment_and_qualification_are_traced_and_update_the_policy(): void
    {
        $nonConformity = NonConformity::factory()->severity(NonConformitySeverity::Minor)->create([
            'detected_by' => $this->operator->getKey(),
            'created_by' => $this->operator->getKey(),
        ]);

        $assigned = $this->service->assign($nonConformity, [
            'responsible_id' => $this->supervisor->getKey(),
            'due_date' => now()->addDays(10)->toDateString(),
            'treatment_plan' => 'Reprise de la pièce et nouveau contrôle.',
            'immediate_action' => 'Isolement de la pièce.',
            'containment_at' => now()->subMinute(),
        ], $this->supervisor);

        $this->assertSame($this->supervisor->getKey(), $assigned->responsible_id);
        $this->assertSame('Reprise de la pièce et nouveau contrôle.', $assigned->treatment_plan);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_assigned',
        ]);

        $qualified = $this->service->qualify($assigned, [
            'severity' => NonConformitySeverity::Major->value,
            'reason' => 'Le défaut compromet la tenue mécanique de l\'assemblage.',
        ], $this->qualityManager);

        $this->assertSame(NonConformitySeverity::Major, $qualified->severity);
        $this->assertSame($this->qualityManager->getKey(), $qualified->qualified_by);
        $this->assertNotNull($qualified->qualified_at);
        $this->assertTrue($qualified->is_blocking);
        $this->assertTrue($qualified->verification_required);
        $this->assertSame('major', $qualified->current_severity_policy_snapshot['severity']);
        $this->assertSame('minor', $qualified->severity_policy_snapshot['severity']);
        $this->assertSame(NonConformitySeverity::Minor, $qualified->initial_severity);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_severity_changed',
        ]);

        $audit = QualityAuditLog::query()
            ->where('subject_type', 'non_conformity')
            ->where('subject_id', $nonConformity->getKey())
            ->where('event', 'non_conformity_severity_changed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('minor', $audit->metadata['previous_severity']);
        $this->assertSame('major', $audit->metadata['severity']);
        $this->assertSame('minor', $audit->metadata['initial_policy']['severity']);
        $this->assertSame('major', $audit->metadata['policy']['severity']);
        $this->assertSame(
            'Le défaut compromet la tenue mécanique de l\'assemblage.',
            $audit->metadata['reason'],
        );
    }

    public function test_the_initial_severity_snapshot_is_never_overwritten_by_requalification(): void
    {
        $nonConformity = NonConformity::factory()->severity(NonConformitySeverity::Minor)->create();
        $initialSnapshot = $nonConformity->severity_policy_snapshot;

        $qualified = $this->service->qualify($nonConformity, [
            'severity' => NonConformitySeverity::Critical->value,
            'reason' => 'Risque sécurité identifié après analyse.',
        ], $this->qualityManager);

        $this->assertSame(NonConformitySeverity::Minor, $qualified->initial_severity);
        $this->assertSame($initialSnapshot, $qualified->severity_policy_snapshot);
        $this->assertSame('critical', $qualified->current_severity_policy_snapshot['severity']);
        $this->assertSame('minor', $qualified->initial_severity->value);
    }

    public function test_a_cancelled_non_conformity_allows_a_new_active_one_from_the_same_result(): void
    {
        [, , , $result] = $this->nonConformingResult();

        $first = $this->service->createFromResult($result, [
            'description' => 'Première NC annulée pour erreur de saisie.',
            'severity' => 'minor',
            'category' => 'other',
        ], $this->operator);

        app(NonConformityLifecycleService::class)->cancel(
            $first,
            $this->supervisor,
            'Erreur de saisie : NC annulée.',
        );

        $second = $this->service->createFromResult($result, [
            'description' => 'Nouvelle NC active après annulation de la première.',
            'severity' => 'major',
            'category' => 'process',
        ], $this->operator);

        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame('cancelled', $first->fresh()->status->value);
        $this->assertSame('open', $second->status->value);
        $this->assertSame(2, NonConformity::query()->where('quality_control_result_id', $result->getKey())->count());
        $this->assertGreaterThanOrEqual(1, $first->fresh()->statusHistories()->count());
    }

    public function test_a_closed_non_conformity_does_not_release_the_result_for_a_new_one(): void
    {
        [, , , $result] = $this->nonConformingResult();

        NonConformity::factory()->severity(NonConformitySeverity::Minor)->create([
            'quality_control_result_id' => $result->getKey(),
            'work_order_id' => $result->work_order_id,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service->createFromResult($result, [
            'description' => 'Tentative de création après clôture.',
            'severity' => 'minor',
            'category' => 'other',
        ], $this->operator);
    }

    public function test_a_workshop_supervisor_cannot_qualify_a_major_non_conformity(): void
    {
        $nonConformity = NonConformity::factory()->severity(NonConformitySeverity::Minor)->create();

        $this->expectException(ValidationException::class);

        $this->service->qualify($nonConformity, [
            'severity' => NonConformitySeverity::Major->value,
            'reason' => 'Tentative de requalification réservée au responsable qualité.',
        ], $this->supervisor);
    }

    public function test_the_severity_policy_snapshot_is_not_changed_by_a_later_config_change(): void
    {
        $nonConformity = NonConformity::factory()->severity(NonConformitySeverity::Minor)->create();

        config(['quality.severities.minor.blocking' => true]);

        $this->assertFalse($nonConformity->fresh()->is_blocking);
        $this->assertFalse($nonConformity->fresh()->severity_policy_snapshot['blocking']);
    }

    public function test_a_conforming_or_rejected_result_cannot_produce_a_non_conformity(): void
    {
        [$workOrder, $control] = $this->nonConformingResult(validate: true);

        $point = $control->fresh()->points()->sole();
        $conforming = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => true],
            $this->operator,
        );

        try {
            $this->service->createFromResult($conforming, [
                'description' => 'Tentative depuis un résultat conforme.',
                'severity' => 'minor',
                'category' => 'other',
            ], $this->operator);
            $this->fail('Un résultat conforme ne doit pas produire de NC.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('result', $exception->errors());
        }

        $rejected = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => false],
            $this->operator,
        );

        app(QualityControlResultService::class)->validate(
            $rejected,
            ['decision' => 'rejected', 'comment' => 'Erreur de saisie.'],
            $this->supervisor,
        );

        try {
            $this->service->createFromResult($rejected->fresh(), [
                'description' => 'Tentative depuis un résultat rejeté.',
                'severity' => 'minor',
                'category' => 'other',
            ], $this->operator);
            $this->fail('Un résultat rejeté ne doit pas produire de NC.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('result', $exception->errors());
        }
    }

    public function test_non_conformity_references_are_sequential_and_unique(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $payload = [
            'description' => 'Non-conformité de référencement séquentiel.',
            'severity' => NonConformitySeverity::Minor->value,
            'category' => NonConformityCategory::Other->value,
            'detected_at' => now(),
        ];

        $first = $this->service->createManual($workOrder, $payload, $this->operator);
        $second = $this->service->createManual($workOrder, $payload, $this->operator);

        $this->assertMatchesRegularExpression('/^NC-\d{4}-\d{4}$/', $first->reference);
        $this->assertSame(
            (int) substr($first->reference, -4) + 1,
            (int) substr($second->reference, -4),
        );
        $this->assertNotSame($first->reference, $second->reference);
    }

    public function test_evidence_is_stored_and_audited(): void
    {
        Storage::fake('documents');

        $nonConformity = NonConformity::factory()->create([
            'created_by' => $this->operator->getKey(),
            'detected_by' => $this->operator->getKey(),
        ]);

        $attachment = $this->service->attachEvidence(
            $nonConformity,
            UploadedFile::fake()->image('preuve-nc.png', 100, 100),
            $this->operator,
        );

        $this->assertSame('preuve-nc.png', $attachment->original_filename);
        $this->assertSame(64, strlen($attachment->sha256));
        Storage::disk('documents')->assertExists($attachment->path);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_evidence_added',
        ]);
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderQualityControl, 2: WorkOrderQualityControlPoint, 3: QualityControlResult}
     */
    protected function nonConformingResult(bool $validate = false): array
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Conformité dimensionnelle']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $point = $control->points()->sole();

        $result = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => false],
            $this->operator,
        );

        if ($validate) {
            app(QualityControlResultService::class)->validate(
                $result,
                ['decision' => 'validated'],
                $this->supervisor,
            );
        }

        return [$workOrder->fresh(), $control->fresh(), $point->fresh(), $result->fresh()];
    }
}
