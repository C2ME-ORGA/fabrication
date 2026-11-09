<?php

namespace Tests\Feature;

use App\Enums\QualityControlTemplateVersionStatus;
use App\Enums\QualityControlType;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use App\Services\QualityControlTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QualityControlTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected QualityControlTemplateService $service;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(QualityControlTemplateService::class);
        $this->actor = User::factory()->qualityManager()->create();
    }

    public function test_creating_a_template_normalizes_its_code_and_creates_a_draft_version(): void
    {
        $template = $this->service->create([
            'code' => '  dim   01 ',
            'name' => 'Contrôle dimensionnel',
            'control_type' => QualityControlType::Dimensional->value,
        ], $this->actor);

        $this->assertSame('DIM 01', $template->code);
        $this->assertFalse($template->is_active);
        $this->assertNull($template->current_version_id);
        $this->assertSame(QualityControlType::Dimensional, $template->control_type);
        $this->assertSame($this->actor->getKey(), $template->created_by);

        $version = $template->versions()->sole();

        $this->assertSame(1, $version->version_number);
        $this->assertSame(QualityControlTemplateVersionStatus::Draft, $version->status);
        $this->assertSame($this->actor->getKey(), $version->created_by);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'template',
            'subject_id' => $template->getKey(),
            'event' => 'template_created',
            'user_id' => $this->actor->getKey(),
        ]);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'template_version',
            'subject_id' => $version->getKey(),
            'event' => 'template_version_created',
        ]);
    }

    public function test_a_duplicate_code_is_refused_case_insensitively(): void
    {
        $this->service->create([
            'code' => 'VIS-01',
            'name' => 'Visuel',
            'control_type' => QualityControlType::Visual->value,
        ], $this->actor);

        try {
            $this->service->create([
                'code' => 'vis-01',
                'name' => 'Doublon',
                'control_type' => QualityControlType::Visual->value,
            ], $this->actor);

            $this->fail('Un code dupliqué aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame(1, QualityControlTemplate::query()->count());
    }

    public function test_updating_a_template_changes_its_identity_and_is_audited(): void
    {
        $template = $this->makeTemplate();

        $updated = $this->service->update($template, [
            'name' => 'Nouveau nom',
            'control_type' => QualityControlType::Final->value,
        ], $this->actor);

        $this->assertSame('Nouveau nom', $updated->name);
        $this->assertSame(QualityControlType::Final, $updated->control_type);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'template',
            'subject_id' => $template->getKey(),
            'event' => 'template_updated',
        ]);
    }

    public function test_protected_fields_cannot_be_updated_through_the_service(): void
    {
        $template = $this->makeTemplate();

        $this->expectException(ValidationException::class);

        $this->service->update($template, ['is_active' => true], $this->actor);
    }

    public function test_a_template_used_by_a_routing_cannot_be_deleted(): void
    {
        $template = $this->makeTemplate();
        [$operation] = $this->makeRoutingWithOperation();

        $requirement = new WorkshopRoutingOperationQualityRequirement;
        $requirement->forceFill([
            'workshop_routing_operation_id' => $operation->getKey(),
            'quality_control_template_id' => $template->getKey(),
            'scope' => 'operation',
            'trigger' => 'on_completion',
            'is_mandatory' => true,
            'position' => 10,
        ])->save();

        try {
            $this->service->delete($template, $this->actor);

            $this->fail('La suppression aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('template', $exception->errors());
        }

        $this->assertDatabaseHas('quality_control_templates', ['id' => $template->getKey()]);
    }

    public function test_an_unused_template_is_deleted_with_its_versions(): void
    {
        $template = $this->makeTemplate();

        $this->assertSame(1, $template->versions()->count());

        $this->service->delete($template, $this->actor);

        $this->assertDatabaseMissing('quality_control_templates', ['id' => $template->getKey()]);
        $this->assertSame(0, $template->versions()->count());
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'template',
            'subject_id' => $template->getKey(),
            'event' => 'template_deleted',
        ]);
    }

    protected function makeTemplate(): QualityControlTemplate
    {
        return $this->service->create([
            'code' => 'TPL-'.uniqid(),
            'name' => 'Modèle de test',
            'control_type' => QualityControlType::Dimensional->value,
        ], $this->actor);
    }

    /**
     * @return array{0: WorkshopRoutingOperation, 1: WorkshopRouting}
     */
    protected function makeRoutingWithOperation(): array
    {
        $routing = WorkshopRouting::factory()->create();
        $operation = WorkshopRoutingOperation::factory()->forRouting($routing)->create();

        return [$operation, $routing];
    }
}
