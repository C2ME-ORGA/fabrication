<?php

namespace Tests\Feature;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Services\QualityControlPlanGenerationService;
use App\Services\QualityControlPointService;
use App\Services\QualityControlTemplateVersionService;
use App\Services\WorkOrderQualityPlanService;
use App\Services\WorkOrderRoutingService;
use App\Services\WorkshopRoutingQualityRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class QualityControlGenerationTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_assignment_generates_controls_and_points_from_routing_requirements(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [
            [
                'label' => 'Aspect visuel',
                'result_type' => QualityControlResultType::Conformity->value,
                'is_mandatory' => true,
            ],
            [
                'label' => 'Longueur',
                'result_type' => QualityControlResultType::Numeric->value,
                'unit' => 'mm',
                'tolerance_min' => 5995,
                'tolerance_max' => 6005,
                'is_critical' => true,
            ],
        ]);

        $workOrder = WorkOrder::factory()->create();
        $this->createItems($workOrder, 3);

        [, $sourceOperation] = $this->assignRequirement(
            $this->admin,
            $workOrder,
            $template,
            QualityRequirementScope::Item->value,
            QualityRequirementTrigger::OnCompletion->value,
        );

        $control = WorkOrderQualityControl::query()
            ->where('work_order_id', $workOrder->getKey())
            ->sole();

        $this->assertSame($template->code, $control->template_code);
        $this->assertSame($template->name, $control->template_name);
        $this->assertSame(1, $control->template_version_number);
        $this->assertSame(QualityRequirementScope::Item, $control->scope);
        $this->assertSame(QualityRequirementTrigger::OnCompletion, $control->trigger);
        $this->assertTrue($control->is_mandatory);
        $this->assertSame(QualityControlStatus::Pending, $control->status);
        $this->assertSame('routing', $control->source_type);
        $this->assertSame($sourceOperation->getKey(), $control->work_order_operation_id);
        $this->assertSame(2, $control->points()->count());

        $points = $control->points()->get()->keyBy('label');

        $this->assertSame('3', (string) $points['Aspect visuel']->required_results);
        $this->assertSame(
            $workOrder->items()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $points['Aspect visuel']->sampledItemIds(),
        );
        $this->assertSame('mm', $points['Longueur']->unit);
        $this->assertTrue($points['Longueur']->is_critical);

        $this->assertDatabaseHas('quality_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'subject_type' => 'control',
            'subject_id' => $control->getKey(),
            'event' => 'control_generated',
        ]);
    }

    public function test_all_every_n_and_fixed_modes_compute_the_expected_required_results(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $items = $this->createItems($workOrder, 10);

        $all = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Toutes les pièces',
            'sampling_mode' => QualityControlSamplingMode::All->value,
        ]], 'TPL-ALL');

        $everyN = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Une sur trois',
            'sampling_mode' => QualityControlSamplingMode::EveryN->value,
            'sampling_every' => 3,
        ]], 'TPL-EVERY');

        $fixed = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Quatre pièces',
            'sampling_mode' => QualityControlSamplingMode::Fixed->value,
            'required_results' => 4,
        ]], 'TPL-FIXED');

        $routing = WorkshopRouting::factory()->create();
        $operation = WorkshopRoutingOperation::factory()->forRouting($routing)->create();
        $requirements = app(WorkshopRoutingQualityRequirementService::class);

        foreach ([$all, $everyN, $fixed] as $template) {
            $requirements->create($operation, [
                'quality_control_template_id' => $template->getKey(),
                'scope' => QualityRequirementScope::Item->value,
                'trigger' => QualityRequirementTrigger::OnCompletion->value,
            ], $this->admin);
        }

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing->fresh(), $this->admin);

        $controls = $workOrder->fresh()->qualityControls()->with('points')->get()->keyBy('template_code');

        $this->assertSame(10, $controls['TPL-ALL']->points->first()->required_results);
        $this->assertSame(4, $controls['TPL-EVERY']->points->first()->required_results);
        $this->assertSame(4, $controls['TPL-FIXED']->points->first()->required_results);

        $this->assertSame(
            [$items[0]->getKey(), $items[3]->getKey(), $items[6]->getKey(), $items[9]->getKey()],
            $controls['TPL-EVERY']->points->first()->sampledItemIds(),
        );
    }

    public function test_every_n_selection_is_reproducible_persisted_and_idempotent(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Une sur trois',
            'sampling_mode' => QualityControlSamplingMode::EveryN->value,
            'sampling_every' => 3,
        ]]);

        $workOrder = WorkOrder::factory()->create();
        $this->createItems($workOrder, 10);

        $this->assignRequirement(
            $this->admin,
            $workOrder,
            $template,
            QualityRequirementScope::Item->value,
        );

        $control = $workOrder->fresh()->qualityControls()->with('points')->sole();
        $point = $control->points->first();
        $firstSelection = $point->sampledItemIds();

        $created = app(QualityControlPlanGenerationService::class)
            ->generateForWorkOrder($workOrder->fresh(), $this->admin);

        $this->assertCount(0, $created);
        $this->assertSame(
            $firstSelection,
            $point->fresh()->sampledItemIds(),
        );
        $this->assertSame(1, $workOrder->fresh()->qualityControls()->count());
    }

    public function test_the_generated_snapshot_is_not_changed_by_a_new_template_version(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Libellé initial',
            'result_type' => QualityControlResultType::Conformity->value,
        ]]);

        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $workOrder, $template);

        $generatedPoint = $workOrder->fresh()->qualityControls()->sole()->points()->sole();
        $this->assertSame('Libellé initial', $generatedPoint->label);

        $newVersion = app(QualityControlTemplateVersionService::class)
            ->createFromActive($template->fresh(), $this->admin, 'Évolution du libellé.');

        $sourcePoint = $newVersion->points()->sole();
        app(QualityControlPointService::class)->update($sourcePoint, [
            'label' => 'Libellé modifié',
        ]);

        app(QualityControlTemplateVersionService::class)
            ->activate($newVersion->fresh(), $this->admin, 'Évolution du libellé.');

        $this->assertSame('Libellé initial', $generatedPoint->fresh()->label);
        $this->assertSame(1, $generatedPoint->fresh()->control->template_version_number);
    }

    public function test_replacing_a_routing_deletes_controls_without_results_and_regenerates_them(): void
    {
        $firstTemplate = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Premier contrôle',
        ]], 'TPL-FIRST');

        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $workOrder, $firstTemplate);

        $oldControl = $workOrder->fresh()->qualityControls()->sole();

        $secondTemplate = $this->makeActiveTemplate($this->admin, [[
            'label' => 'Second contrôle',
        ]], 'TPL-SECOND');

        $newRouting = WorkshopRouting::factory()->create();
        $newOperation = WorkshopRoutingOperation::factory()->forRouting($newRouting)->create();

        app(WorkshopRoutingQualityRequirementService::class)->create($newOperation, [
            'quality_control_template_id' => $secondTemplate->getKey(),
            'scope' => QualityRequirementScope::Operation->value,
            'trigger' => QualityRequirementTrigger::OnCompletion->value,
        ], $this->admin);

        app(WorkOrderRoutingService::class)->replace($workOrder->fresh(), $newRouting->fresh(), $this->admin);

        $this->assertDatabaseMissing('work_order_quality_controls', ['id' => $oldControl->getKey()]);
        $this->assertSame(1, $workOrder->fresh()->qualityControls()->count());
        $this->assertSame('TPL-SECOND', $workOrder->fresh()->qualityControls()->sole()->template_code);
    }

    public function test_an_operation_carrying_a_quality_control_cannot_be_deleted(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $workOrder, $template);

        $operation = $workOrder->fresh()->operations()->sole();

        $this->expectException(ValidationException::class);

        app(WorkOrderRoutingService::class)->deleteOperation($operation, $this->admin);
    }

    public function test_a_new_generation_uses_the_active_template_version(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Version un']]);

        $firstWorkOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $firstWorkOrder, $template);

        $newVersion = app(QualityControlTemplateVersionService::class)
            ->createFromActive($template->fresh(), $this->admin, 'Deuxième version.');

        app(QualityControlPointService::class)->update($newVersion->points()->sole(), [
            'label' => 'Version deux',
        ]);

        app(QualityControlTemplateVersionService::class)
            ->activate($newVersion->fresh(), $this->admin, 'Deuxième version.');

        $secondWorkOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $secondWorkOrder, $template->fresh());

        $firstControl = $firstWorkOrder->fresh()->qualityControls()->sole();
        $secondControl = $secondWorkOrder->fresh()->qualityControls()->sole();

        $this->assertSame(1, $firstControl->template_version_number);
        $this->assertSame('Version un', $firstControl->points()->sole()->label);
        $this->assertSame(2, $secondControl->template_version_number);
        $this->assertSame('Version deux', $secondControl->points()->sole()->label);
    }

    public function test_a_template_can_be_added_manually_before_release_only(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle manuel']]);
        $workOrder = WorkOrder::factory()->create();

        $control = app(WorkOrderQualityPlanService::class)->addTemplate(
            $workOrder,
            $template,
            [
                'scope' => QualityRequirementScope::WorkOrder->value,
                'trigger' => QualityRequirementTrigger::Final->value,
                'is_mandatory' => true,
            ],
            $this->admin,
        );

        $this->assertSame('manual', $control->source_type);
        $this->assertNull($control->source_requirement_id);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $control->getKey(),
            'event' => 'control_added_manually',
        ]);

        $this->release($workOrder);

        $this->expectException(ValidationException::class);

        app(WorkOrderQualityPlanService::class)->addTemplate(
            $workOrder->fresh(),
            $template,
            [],
            $this->admin,
        );
    }

    public function test_a_manual_control_can_be_deleted_before_release_with_audit(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle manuel']]);
        $workOrder = WorkOrder::factory()->create();

        $control = app(WorkOrderQualityPlanService::class)->addTemplate(
            $workOrder,
            $template,
            ['scope' => QualityRequirementScope::Operation->value],
            $this->admin,
        );

        $controlId = $control->getKey();

        app(WorkOrderQualityPlanService::class)->deleteManualControl($control, $this->admin);

        $this->assertDatabaseMissing('work_order_quality_controls', ['id' => $controlId]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $controlId,
            'event' => 'control_deleted',
        ]);
    }

    public function test_a_manual_control_cannot_be_deleted_after_release_or_with_results(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle manuel']]);

        $workOrderWithResult = WorkOrder::factory()->create();
        $controlWithResult = app(WorkOrderQualityPlanService::class)->addTemplate(
            $workOrderWithResult,
            $template,
            ['scope' => QualityRequirementScope::Operation->value],
            $this->admin,
        );

        QualityControlResult::factory()->create([
            'work_order_quality_control_point_id' => $controlWithResult->points()->sole()->getKey(),
            'work_order_id' => $workOrderWithResult->getKey(),
            'performed_by' => $this->admin->getKey(),
            'recorded_by' => $this->admin->getKey(),
            'performed_at' => now(),
            'created_at' => now(),
        ]);

        try {
            app(WorkOrderQualityPlanService::class)->deleteManualControl($controlWithResult, $this->admin);
            $this->fail('Un contrôle portant des résultats ne doit pas être supprimable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality_control', $exception->errors());
        }

        $this->assertDatabaseHas('work_order_quality_controls', ['id' => $controlWithResult->getKey()]);

        $releasedWorkOrder = WorkOrder::factory()->create();
        $releasedControl = app(WorkOrderQualityPlanService::class)->addTemplate(
            $releasedWorkOrder,
            $template,
            ['scope' => QualityRequirementScope::Operation->value],
            $this->admin,
        );

        $this->release($releasedWorkOrder);

        try {
            app(WorkOrderQualityPlanService::class)->deleteManualControl($releasedControl, $this->admin);
            $this->fail('Un contrôle doit être figé après libération.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality_control', $exception->errors());
        }

        $this->assertDatabaseHas('work_order_quality_controls', ['id' => $releasedControl->getKey()]);
    }

    public function test_a_generated_control_cannot_be_deleted_physically(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle généré']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $workOrder, $template);

        $control = $workOrder->fresh()->qualityControls()->sole();

        $this->expectException(ValidationException::class);

        app(WorkOrderQualityPlanService::class)->deleteManualControl($control, $this->admin);
    }

    public function test_an_inactive_template_prevents_generation(): void
    {
        $template = $this->makeActiveTemplate($this->admin, [['label' => 'Contrôle']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($this->admin, $workOrder, $template);

        // Une exigence ne peut pas être ajoutée avec un modèle inactif ; le
        // contrôle déjà généré reste indépendant de la désactivation.
        app(QualityControlTemplateVersionService::class)->deactivate($template->fresh(), $this->admin);

        $this->assertSame(1, $workOrder->fresh()->qualityControls()->count());
    }
}
