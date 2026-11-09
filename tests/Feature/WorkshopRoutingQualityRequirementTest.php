<?php

namespace Tests\Feature;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use App\Services\QualityControlPointService;
use App\Services\QualityControlTemplateService;
use App\Services\QualityControlTemplateVersionService;
use App\Services\WorkshopRoutingQualityRequirementService;
use App\Services\WorkshopRoutingValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkshopRoutingQualityRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected WorkshopRoutingQualityRequirementService $service;

    protected QualityControlTemplateService $templates;

    protected QualityControlPointService $points;

    protected QualityControlTemplateVersionService $versions;

    protected User $actor;

    protected WorkshopRouting $routing;

    protected WorkshopRoutingOperation $operation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkshopRoutingQualityRequirementService::class);
        $this->templates = app(QualityControlTemplateService::class);
        $this->points = app(QualityControlPointService::class);
        $this->versions = app(QualityControlTemplateVersionService::class);
        $this->actor = User::factory()->qualityManager()->create();

        $this->routing = WorkshopRouting::factory()->create();
        $this->operation = WorkshopRoutingOperation::factory()->forRouting($this->routing)->create();
    }

    public function test_a_requirement_can_be_added_with_an_active_template(): void
    {
        $template = $this->makeActiveTemplate();

        $requirement = $this->service->create($this->operation, [
            'quality_control_template_id' => $template->getKey(),
            'scope' => QualityRequirementScope::Operation->value,
            'trigger' => QualityRequirementTrigger::OnCompletion->value,
            'is_mandatory' => true,
        ], $this->actor);

        $this->assertSame($template->getKey(), $requirement->quality_control_template_id);
        $this->assertSame(10, $requirement->position);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'requirement',
            'subject_id' => $requirement->getKey(),
            'event' => 'requirement_added',
        ]);
    }

    public function test_an_inactive_template_cannot_be_required(): void
    {
        $template = $this->templates->create([
            'code' => 'INACT-01',
            'name' => 'Inactif',
            'control_type' => QualityControlType::Visual->value,
        ], $this->actor);

        try {
            $this->service->create($this->operation, [
                'quality_control_template_id' => $template->getKey(),
                'scope' => QualityRequirementScope::Operation->value,
                'trigger' => QualityRequirementTrigger::OnCompletion->value,
            ], $this->actor);

            $this->fail('Un modèle inactif aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality_control_template_id', $exception->errors());
        }
    }

    public function test_the_same_template_cannot_be_required_twice_on_one_operation(): void
    {
        $template = $this->makeActiveTemplate();

        $data = [
            'quality_control_template_id' => $template->getKey(),
            'scope' => QualityRequirementScope::Operation->value,
            'trigger' => QualityRequirementTrigger::OnCompletion->value,
        ];

        $this->service->create($this->operation, $data, $this->actor);

        $this->expectException(ValidationException::class);

        $this->service->create($this->operation, $data, $this->actor);
    }

    public function test_frequency_sampling_is_refused_on_operation_scope(): void
    {
        $template = $this->makeActiveTemplate(QualityControlSamplingMode::EveryN, 5);

        try {
            $this->service->create($this->operation, [
                'quality_control_template_id' => $template->getKey(),
                'scope' => QualityRequirementScope::Operation->value,
                'trigger' => QualityRequirementTrigger::OnCompletion->value,
            ], $this->actor);

            $this->fail('L\'échantillonnage par fréquence aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope', $exception->errors());
        }
    }

    public function test_frequency_sampling_is_allowed_on_item_scope(): void
    {
        $template = $this->makeActiveTemplate(QualityControlSamplingMode::EveryN, 5);

        $requirement = $this->service->create($this->operation, [
            'quality_control_template_id' => $template->getKey(),
            'scope' => QualityRequirementScope::Item->value,
            'trigger' => QualityRequirementTrigger::OnCompletion->value,
        ], $this->actor);

        $this->assertSame(QualityRequirementScope::Item, $requirement->scope);
    }

    public function test_a_requirement_can_be_updated_and_removed_with_audit(): void
    {
        $template = $this->makeActiveTemplate();

        $requirement = $this->service->create($this->operation, [
            'quality_control_template_id' => $template->getKey(),
            'scope' => QualityRequirementScope::Operation->value,
            'trigger' => QualityRequirementTrigger::OnCompletion->value,
        ], $this->actor);

        $updated = $this->service->update($requirement, [
            'scope' => QualityRequirementScope::Item->value,
            'trigger' => QualityRequirementTrigger::Final->value,
            'is_mandatory' => false,
        ], $this->actor);

        $this->assertSame(QualityRequirementScope::Item, $updated->scope);
        $this->assertSame(QualityRequirementTrigger::Final, $updated->trigger);
        $this->assertFalse($updated->is_mandatory);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'requirement',
            'subject_id' => $requirement->getKey(),
            'event' => 'requirement_updated',
        ]);

        $this->service->delete($updated, $this->actor);

        $this->assertDatabaseMissing('workshop_routing_operation_quality_requirements', [
            'id' => $requirement->getKey(),
        ]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'requirement',
            'event' => 'requirement_removed',
        ]);
    }

    public function test_routing_validation_reports_an_inactive_template(): void
    {
        $template = $this->templates->create([
            'code' => 'INACT-02',
            'name' => 'Inactif',
            'control_type' => QualityControlType::Visual->value,
        ], $this->actor);

        $requirement = new WorkshopRoutingOperationQualityRequirement;
        $requirement->forceFill([
            'workshop_routing_operation_id' => $this->operation->getKey(),
            'quality_control_template_id' => $template->getKey(),
            'scope' => 'operation',
            'trigger' => 'on_completion',
            'is_mandatory' => true,
            'position' => 10,
        ])->save();

        $validation = app(WorkshopRoutingValidationService::class);
        $errors = $validation->errors($this->routing->fresh());

        $this->assertArrayHasKey('quality_requirements', $errors);
        $this->assertNotEmpty($errors['quality_requirements']);
    }

    protected function makeActiveTemplate(
        QualityControlSamplingMode $sampling = QualityControlSamplingMode::All,
        ?int $every = null,
    ): QualityControlTemplate {
        $template = $this->templates->create([
            'code' => 'TPL-'.uniqid(),
            'name' => 'Modèle actif',
            'control_type' => QualityControlType::Dimensional->value,
        ], $this->actor);

        $version = $template->draftVersion();

        $this->points->create($version, [
            'label' => 'Point',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => $sampling->value,
            'sampling_every' => $every,
            'is_mandatory' => true,
        ]);

        $this->versions->activate($version->fresh(), $this->actor, 'Version initiale.');

        return $template->fresh();
    }
}
