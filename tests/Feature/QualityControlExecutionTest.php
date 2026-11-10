<?php

namespace Tests\Feature;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Models\QualityAuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\QualityControlEvaluationService;
use App\Services\QualityControlResultService;
use App\Services\WorkOrderItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class QualityControlExecutionTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected User $supervisor;

    protected User $qualityManager;

    protected QualityControlResultService $results;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->qualityManager = User::factory()->qualityManager()->create();
        $this->results = app(QualityControlResultService::class);
    }

    public function test_numeric_tolerances_are_inclusive_and_out_of_range_is_non_conforming(): void
    {
        [$workOrder, $point] = $this->preparedControl([[
            'label' => 'Longueur',
            'result_type' => QualityControlResultType::Numeric->value,
            'unit' => 'mm',
            'tolerance_min' => 10,
            'tolerance_max' => 20,
        ]]);

        $atMin = $this->results->record($point, ['value_numeric' => 10], $this->operator);
        $atMax = $this->results->record($point, ['value_numeric' => 20], $this->operator);
        $over = $this->results->record($point, ['value_numeric' => 20.0001], $this->operator);
        $under = $this->results->record($point, ['value_numeric' => 9.9999], $this->operator);

        $this->assertTrue($atMin->is_conform);
        $this->assertTrue($atMax->is_conform);
        $this->assertFalse($over->is_conform);
        $this->assertFalse($under->is_conform);
        $this->assertSame('inclusive_range', $over->evaluation_detail['rule']);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'result',
            'subject_id' => $atMin->getKey(),
            'event' => 'result_recorded',
        ]);
    }

    public function test_a_numeric_measure_without_bounds_is_informative(): void
    {
        [, $point] = $this->preparedControl([[
            'label' => 'Mesure informative',
            'result_type' => QualityControlResultType::Numeric->value,
            'unit' => 'mm',
        ]]);

        $result = $this->results->record($point, ['value_numeric' => 123.45], $this->operator);

        $this->assertNull($result->is_conform);
        $this->assertSame('informative', $result->evaluation_detail['rule']);
    }

    public function test_text_requires_an_explicit_conformity_indicator(): void
    {
        [, $point] = $this->preparedControl([[
            'label' => 'Observation',
            'result_type' => QualityControlResultType::Text->value,
        ]]);

        try {
            $this->results->record($point, ['value_text' => 'Rayure légère'], $this->operator);
            $this->fail('La conformité explicite aurait dû être exigée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_conform', $exception->errors());
        }

        $result = $this->results->record($point, [
            'value_text' => 'Rayure légère',
            'is_conform' => false,
        ], $this->operator);

        $this->assertSame('Rayure légère', $result->value_text);
        $this->assertFalse($result->is_conform);
        $this->assertSame('explicit_user_conformity', $result->evaluation_detail['rule']);
    }

    public function test_choice_results_derive_conformity_from_the_selected_option(): void
    {
        [, $point] = $this->preparedControl([[
            'label' => 'Aspect',
            'result_type' => QualityControlResultType::Choice->value,
            'options' => [
                ['value' => 'ok', 'label' => 'Conforme', 'conformant' => true],
                ['value' => 'nok', 'label' => 'Non conforme', 'conformant' => false],
            ],
        ]]);

        $conform = $this->results->record($point, ['value_choice' => 'ok'], $this->operator);
        $nonConform = $this->results->record($point, ['value_choice' => 'nok'], $this->operator);

        $this->assertTrue($conform->is_conform);
        $this->assertFalse($nonConform->is_conform);
        $this->assertSame('option_conformant_flag', $nonConform->evaluation_detail['rule']);
    }

    public function test_a_single_validation_passes_a_non_critical_control_and_the_controller_cannot_validate(): void
    {
        [, $point] = $this->preparedControl([[
            'label' => 'Conformité visuelle',
        ]]);

        $result = $this->results->record($point, ['value_boolean' => true], $this->operator);

        try {
            $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->operator);
            $this->fail('Un opérateur ne doit pas pouvoir valider un résultat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality_control', $exception->errors());
        }

        $validation = $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->supervisor);

        $this->assertSame('validated', $validation->decision->value);
        $this->assertTrue($result->fresh()->isFullyValidated());
        $this->assertSame(QualityControlStatus::Passed, $point->control->fresh()->status);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'result',
            'subject_id' => $result->getKey(),
            'event' => 'result_validated',
        ]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $point->control->getKey(),
            'event' => 'control_status_changed',
            'to_status' => QualityControlStatus::Passed->value,
        ]);
    }

    public function test_a_failed_operation_scoped_control_can_be_recontrolled_by_a_new_result(): void
    {
        [, $point] = $this->preparedControl([['label' => 'Contrôle process']]);
        $control = $point->control()->first();

        $failed = $this->results->record($point, ['value_boolean' => false], $this->operator);
        $this->results->validate($failed->fresh(), ['decision' => 'validated'], $this->supervisor);

        $this->assertSame(QualityControlStatus::Failed, $control->fresh()->status);

        $recontrol = $this->results->record($point, ['value_boolean' => true], $this->operator);
        $this->results->validate($recontrol->fresh(), ['decision' => 'validated'], $this->supervisor);

        $this->assertSame(QualityControlStatus::Passed, $control->fresh()->status);
        $this->assertSame(2, $point->results()->count());

        $audit = QualityAuditLog::query()
            ->where('subject_type', 'control')
            ->where('subject_id', $control->getKey())
            ->where('event', 'control_status_changed')
            ->where('to_status', QualityControlStatus::Passed->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            QualityControlEvaluationService::RULE_OPERATION_OR_WORK_ORDER,
            $audit->metadata['evaluation_rule'],
        );
        $this->assertSame([$recontrol->getKey()], $audit->metadata['retained_result_ids']);
        $this->assertTrue($audit->metadata['historical_results_preserved']);
    }

    public function test_work_order_scope_uses_only_the_latest_validated_results_within_required_results(): void
    {
        [, $point] = $this->preparedControl(
            [[
                'label' => 'Contrôle final répété',
                'sampling_mode' => QualityControlSamplingMode::Fixed->value,
                'required_results' => 2,
            ]],
            QualityRequirementScope::WorkOrder->value,
        );
        $control = $point->control()->first();

        $nonConform = $this->results->record($point, ['value_boolean' => false], $this->operator);
        $this->results->validate($nonConform->fresh(), ['decision' => 'validated'], $this->supervisor);
        $this->assertSame(QualityControlStatus::Failed, $control->fresh()->status);

        $firstConform = $this->results->record($point, ['value_boolean' => true], $this->operator);
        $this->results->validate($firstConform->fresh(), ['decision' => 'validated'], $this->supervisor);
        $this->assertSame(QualityControlStatus::Failed, $control->fresh()->status);

        $secondConform = $this->results->record($point, ['value_boolean' => true], $this->operator);
        $this->results->validate($secondConform->fresh(), ['decision' => 'validated'], $this->supervisor);

        $this->assertSame(QualityControlStatus::Passed, $control->fresh()->status);
        $this->assertSame(3, $point->results()->count());

        $audit = QualityAuditLog::query()
            ->where('subject_type', 'control')
            ->where('subject_id', $control->getKey())
            ->where('event', 'control_status_changed')
            ->where('to_status', QualityControlStatus::Passed->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            QualityControlEvaluationService::RULE_OPERATION_OR_WORK_ORDER,
            $audit->metadata['evaluation_rule'],
        );
        $this->assertSame(
            [$firstConform->getKey(), $secondConform->getKey()],
            $audit->metadata['retained_result_ids'],
        );
        $this->assertTrue($audit->metadata['historical_results_preserved']);
        $this->assertDatabaseHas('quality_control_results', ['id' => $nonConform->getKey()]);
    }

    public function test_item_scope_required_results_count_distinct_items_only(): void
    {
        [$workOrder, $point] = $this->preparedControl(
            [['label' => 'Contrôle par repère']],
            QualityRequirementScope::Item->value,
            items: 2,
        );

        $items = $workOrder->fresh()->items()->orderBy('id')->get();

        $first = $this->results->record($point, [
            'value_boolean' => true,
            'work_order_item_id' => $items[0]->getKey(),
        ], $this->operator);
        $this->results->validate($first->fresh(), ['decision' => 'validated'], $this->supervisor);

        // Un second résultat sur le même repère remplace le premier dans le
        // décompte : il ne permet pas d'atteindre les deux repères distincts.
        $duplicate = $this->results->record($point, [
            'value_boolean' => true,
            'work_order_item_id' => $items[0]->getKey(),
        ], $this->operator);
        $this->results->validate($duplicate->fresh(), ['decision' => 'validated'], $this->supervisor);

        $this->assertSame(QualityControlStatus::InProgress, $point->control->fresh()->status);

        $second = $this->results->record($point, [
            'value_boolean' => true,
            'work_order_item_id' => $items[1]->getKey(),
        ], $this->operator);
        $this->results->validate($second->fresh(), ['decision' => 'validated'], $this->supervisor);

        $this->assertSame(QualityControlStatus::Passed, $point->control->fresh()->status);
    }

    public function test_evidence_is_stored_with_a_hash_and_attached_to_the_result(): void
    {
        Storage::fake('documents');

        [, $point] = $this->preparedControl([['label' => 'Contrôle avec preuve']]);

        $result = $this->results->record($point, [
            'value_boolean' => true,
            'evidence' => [UploadedFile::fake()->image('preuve.png', 120, 120)],
        ], $this->operator);

        $attachment = $result->attachments()->sole();

        $this->assertSame('documents', $attachment->disk);
        $this->assertSame('preuve.png', $attachment->original_filename);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame(64, strlen($attachment->sha256));
        Storage::disk('documents')->assertExists($attachment->path);
    }

    public function test_a_supervisor_cannot_validate_their_own_result(): void
    {
        [, $point] = $this->preparedControl([['label' => 'Conformité visuelle']]);

        $result = $this->results->record($point, ['value_boolean' => true], $this->supervisor);

        $this->expectException(ValidationException::class);

        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->supervisor);
    }

    public function test_a_critical_point_requires_two_distinct_validators_outside_the_controller(): void
    {
        [, $point] = $this->preparedControl([[
            'label' => 'Point critique',
            'is_critical' => true,
        ]]);

        $result = $this->results->record($point, ['value_boolean' => true], $this->operator);

        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->supervisor);
        $this->assertSame(1, $result->fresh()->validations()->count());
        $this->assertSame(QualityControlStatus::InProgress, $point->control->fresh()->status);

        try {
            $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->supervisor);
            $this->fail('Un même utilisateur ne doit pas valider deux fois.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->qualityManager);

        $this->assertSame(2, $result->fresh()->validations()->count());
        $this->assertTrue($result->fresh()->isFullyValidated());
        $this->assertSame(QualityControlStatus::Passed, $point->control->fresh()->status);
    }

    public function test_a_rejection_must_be_commented_and_invalidates_the_result(): void
    {
        [, $point] = $this->preparedControl([['label' => 'Conformité visuelle']]);
        $result = $this->results->record($point, ['value_boolean' => false], $this->operator);

        try {
            $this->results->validate($result->fresh(), ['decision' => 'rejected'], $this->supervisor);
            $this->fail('Un rejet sans commentaire aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('comment', $exception->errors());
        }

        $this->results->validate($result->fresh(), [
            'decision' => 'rejected',
            'comment' => 'Mesure mal relevée.',
        ], $this->supervisor);

        $this->assertTrue($result->fresh()->isRejected());
        $this->assertFalse($result->fresh()->isFullyValidated());
        $this->assertSame(QualityControlStatus::InProgress, $point->control->fresh()->status);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'result',
            'subject_id' => $result->getKey(),
            'event' => 'result_rejected',
        ]);

        $this->expectException(ValidationException::class);
        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->qualityManager);
    }

    public function test_a_work_order_scoped_control_accepts_results_at_work_order_level(): void
    {
        [$workOrder, $point] = $this->preparedControl(
            [['label' => 'Contrôle final documentaire']],
            QualityRequirementScope::WorkOrder->value,
        );

        $control = $point->control()->first();

        $this->assertSame(QualityRequirementScope::WorkOrder, $control->scope);
        $this->assertNotNull($control->work_order_operation_id);

        $result = $this->results->record($point, ['value_boolean' => true], $this->operator);

        $this->assertNull($result->work_order_item_id);
        $this->assertSame($workOrder->getKey(), $result->work_order_id);
    }

    public function test_an_item_scoped_control_requires_a_valid_item_from_the_work_order(): void
    {
        [$workOrder, $point] = $this->preparedControl(
            [['label' => 'Contrôle dimensionnel']],
            QualityRequirementScope::Item->value,
            items: 2,
        );

        $otherWorkOrder = WorkOrder::factory()->create();
        $foreignItem = WorkOrderItem::factory()->forWorkOrder($otherWorkOrder)->create();

        try {
            $this->results->record($point, ['value_boolean' => true], $this->operator);
            $this->fail('Un repère aurait dû être exigé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order_item_id', $exception->errors());
        }

        try {
            $this->results->record($point, [
                'value_boolean' => true,
                'work_order_item_id' => $foreignItem->getKey(),
            ], $this->operator);
            $this->fail('Un repère d\'un autre OF aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order_item_id', $exception->errors());
        }

        $item = $workOrder->fresh()->items()->orderBy('id')->first();
        $result = $this->results->record($point, [
            'value_boolean' => true,
            'work_order_item_id' => $item->getKey(),
        ], $this->operator);

        $this->assertSame($item->getKey(), $result->work_order_item_id);
    }

    public function test_every_n_only_accepts_the_deterministically_selected_items(): void
    {
        [$workOrder, $point] = $this->preparedControl(
            [[
                'label' => 'Une sur deux',
                'sampling_mode' => QualityControlSamplingMode::EveryN->value,
                'sampling_every' => 2,
            ]],
            QualityRequirementScope::Item->value,
            items: 6,
        );

        $items = $workOrder->fresh()->items()->orderBy('id')->get();
        $selected = $point->sampledItemIds();

        $this->assertSame([$items[0]->getKey(), $items[2]->getKey(), $items[4]->getKey()], $selected);

        $rejectedItem = $items[1];

        try {
            $this->results->record($point, [
                'value_boolean' => true,
                'work_order_item_id' => $rejectedItem->getKey(),
            ], $this->operator);

            $this->fail('Un repère hors échantillonnage aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order_item_id', $exception->errors());
        }

        $result = $this->results->record($point, [
            'value_boolean' => true,
            'work_order_item_id' => $selected[0],
        ], $this->operator);

        $this->assertSame($selected[0], $result->work_order_item_id);
    }

    public function test_result_dates_cannot_be_in_the_future_or_before_release(): void
    {
        [$workOrder, $point] = $this->preparedControl([['label' => 'Contrôle']]);

        try {
            $this->results->record($point, [
                'value_boolean' => true,
                'performed_at' => now()->addHour(),
            ], $this->operator);
            $this->fail('Une date future aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('performed_at', $exception->errors());
        }

        try {
            $this->results->record($point, [
                'value_boolean' => true,
                'performed_at' => $workOrder->released_at->copy()->subMinute(),
            ], $this->operator);
            $this->fail('Une date antérieure à la libération aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('performed_at', $exception->errors());
        }
    }

    public function test_results_and_validations_are_append_only(): void
    {
        [, $point] = $this->preparedControl([['label' => 'Contrôle']]);
        $result = $this->results->record($point, ['value_boolean' => true], $this->operator);
        $validation = $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->supervisor);

        foreach ([$result, $validation] as $model) {
            try {
                $model->comment = 'modification interdite';
                $model->save();
                $this->fail('La modification aurait dû être refusée.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }

            try {
                $model->delete();
                $this->fail('La suppression aurait dû être refusée.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseHas('quality_control_results', ['id' => $result->getKey()]);
        $this->assertDatabaseHas('quality_control_result_validations', ['id' => $validation->getKey()]);
    }

    public function test_adding_an_item_recalculates_item_scoped_required_results_with_audit(): void
    {
        [$workOrder, $control] = $this->preparedDraftControl(
            [['label' => 'Toutes les pièces']],
            QualityRequirementScope::Item->value,
            items: 2,
        );

        $point = $control->points()->sole();
        $this->assertSame(2, $point->required_results);

        app(WorkOrderItemService::class)->create($workOrder, [
            'mark' => 'REP-ADD',
            'designation' => 'Pièce ajoutée',
            'quantity_planned' => 1,
        ], $this->admin);

        $this->assertSame(3, $point->fresh()->required_results);
        $this->assertCount(3, $point->fresh()->sampledItemIds());

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $control->getKey(),
            'event' => 'sampling_plan_recalculated',
        ]);
    }

    public function test_an_item_referenced_by_a_quality_result_cannot_be_deleted(): void
    {
        [$workOrder, $point] = $this->preparedControl(
            [['label' => 'Contrôle']],
            QualityRequirementScope::Item->value,
            items: 1,
        );

        $item = $workOrder->fresh()->items()->sole();

        $this->results->record($point, [
            'value_boolean' => true,
            'work_order_item_id' => $item->getKey(),
        ], $this->operator);

        $this->expectException(ValidationException::class);

        app(WorkOrderItemService::class)->delete($item->fresh(), $this->admin);
    }

    public function test_the_administrator_cannot_bypass_segregation_or_double_validation(): void
    {
        [, $point] = $this->preparedControl([['label' => 'Point critique', 'is_critical' => true]]);

        $result = $this->results->record($point, ['value_boolean' => true], $this->admin);

        try {
            $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->admin);
            $this->fail('L\'administrateur ne doit pas valider son propre résultat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->supervisor);
        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->qualityManager);

        $this->expectException(ValidationException::class);
        $this->results->validate($result->fresh(), ['decision' => 'validated'], $this->admin);
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @return array{0: WorkOrder, 1: WorkOrderQualityControlPoint}
     */
    protected function preparedControl(array $points, string $scope = QualityRequirementScope::Operation->value, int $items = 0): array
    {
        [$workOrder, $control] = $this->preparedDraftControl($points, $scope, $items);

        $this->release($workOrder);

        return [$workOrder->fresh(), $control->fresh()->points()->sole()];
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @return array{0: WorkOrder, 1: WorkOrderQualityControl}
     */
    protected function preparedDraftControl(array $points, string $scope = QualityRequirementScope::Operation->value, int $items = 0): array
    {
        $template = $this->makeActiveTemplate($this->admin, $points);
        $workOrder = WorkOrder::factory()->create();

        if ($items > 0) {
            $this->createItems($workOrder, $items);
        }

        $this->assignRequirement($this->admin, $workOrder, $template, $scope);

        $control = $workOrder->fresh()->qualityControls()->sole();

        return [$workOrder->fresh(), $control];
    }
}
