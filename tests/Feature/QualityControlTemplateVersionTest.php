<?php

namespace Tests\Feature;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlTemplateVersionStatus;
use App\Enums\QualityControlType;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Models\User;
use App\Services\QualityControlPointService;
use App\Services\QualityControlTemplateService;
use App\Services\QualityControlTemplateVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QualityControlTemplateVersionTest extends TestCase
{
    use RefreshDatabase;

    protected QualityControlTemplateService $templates;

    protected QualityControlTemplateVersionService $versions;

    protected QualityControlPointService $points;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templates = app(QualityControlTemplateService::class);
        $this->versions = app(QualityControlTemplateVersionService::class);
        $this->points = app(QualityControlPointService::class);
        $this->actor = User::factory()->qualityManager()->create();
    }

    public function test_activation_requires_at_least_one_point(): void
    {
        $template = $this->makeTemplate();
        $version = $template->draftVersion();

        try {
            $this->versions->activate($version, $this->actor);

            $this->fail('L\'activation aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('points', $exception->errors());
        }

        $this->assertSame(QualityControlTemplateVersionStatus::Draft, $version->fresh()->status);
    }

    public function test_activation_requires_at_least_one_mandatory_point(): void
    {
        $template = $this->makeTemplate();
        $version = $template->draftVersion();

        $this->points->create($version, [
            'label' => 'Point facultatif',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'is_mandatory' => false,
        ]);

        try {
            $this->versions->activate($version->fresh(), $this->actor);

            $this->fail('L\'activation aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('points', $exception->errors());
        }
    }

    public function test_activation_refuses_an_incoherent_numeric_point(): void
    {
        $template = $this->makeTemplate();
        $version = $template->draftVersion();

        QualityControlPoint::factory()->create([
            'quality_control_template_version_id' => $version->getKey(),
            'position' => 10,
            'result_type' => QualityControlResultType::Numeric,
            'unit' => null,
            'is_mandatory' => true,
        ]);

        try {
            $this->versions->activate($version->fresh(), $this->actor);

            $this->fail('L\'activation aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('points', $exception->errors());
        }
    }

    public function test_activation_sets_the_template_active_and_the_current_version(): void
    {
        $template = $this->makeTemplate();
        $version = $this->addMandatoryPoint($template);

        $activated = $this->versions->activate($version, $this->actor, 'Version initiale.');

        $this->assertSame(QualityControlTemplateVersionStatus::Active, $activated->status);
        $this->assertNotNull($activated->activated_at);
        $this->assertSame($this->actor->getKey(), $activated->activated_by);

        $template->refresh();
        $this->assertTrue($template->is_active);
        $this->assertSame($activated->getKey(), $template->current_version_id);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'template_version',
            'subject_id' => $activated->getKey(),
            'event' => 'template_version_activated',
        ]);
    }

    public function test_copy_on_write_copies_points_without_altering_the_active_version(): void
    {
        $template = $this->makeTemplate();
        $firstVersion = $this->addMandatoryPoint($template);
        $this->versions->activate($firstVersion, $this->actor, 'Version initiale.');

        $draft = $this->versions->createFromActive($template->fresh(), $this->actor, 'Évolution de la tolérance.');

        $this->assertSame(2, $draft->version_number);
        $this->assertSame(QualityControlTemplateVersionStatus::Draft, $draft->status);
        $this->assertSame(1, $draft->points()->count());
        $this->assertSame($firstVersion->points()->sole()->label, $draft->points()->sole()->label);

        $this->points->update($draft->points()->sole(), ['label' => 'Libellé modifié']);

        $this->assertSame('Libellé modifié', $draft->points()->sole()->label);
        $this->assertNotSame('Libellé modifié', $firstVersion->points()->sole()->label);
    }

    public function test_activating_a_new_version_retires_the_previous_one(): void
    {
        $template = $this->makeTemplate();
        $firstVersion = $this->addMandatoryPoint($template);
        $this->versions->activate($firstVersion, $this->actor, 'Version initiale.');

        $draft = $this->versions->createFromActive($template->fresh(), $this->actor, 'Deuxième version.');
        $this->versions->activate($draft, $this->actor, 'Deuxième version.');

        $this->assertSame(QualityControlTemplateVersionStatus::Retired, $firstVersion->fresh()->status);
        $this->assertNotNull($firstVersion->fresh()->retired_at);
        $this->assertSame(QualityControlTemplateVersionStatus::Active, $draft->fresh()->status);

        $this->assertSame(1, QualityControlTemplateVersion::query()
            ->where('quality_control_template_id', $template->getKey())
            ->where('status', QualityControlTemplateVersionStatus::Active->value)
            ->count());
    }

    public function test_a_change_note_is_required_after_the_first_version(): void
    {
        $template = $this->makeTemplate();
        $firstVersion = $this->addMandatoryPoint($template);
        $this->versions->activate($firstVersion, $this->actor, 'Version initiale.');

        $draft = $this->versions->createFromActive($template->fresh(), $this->actor);

        try {
            $this->versions->activate($draft, $this->actor, '');

            $this->fail('L\'activation aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('change_note', $exception->errors());
        }
    }

    public function test_deactivation_retires_the_active_version(): void
    {
        $template = $this->makeTemplate();
        $version = $this->addMandatoryPoint($template);
        $this->versions->activate($version, $this->actor, 'Version initiale.');

        $this->templates->deactivate($template->fresh(), $this->actor);

        $this->assertFalse($template->fresh()->is_active);
        $this->assertSame(QualityControlTemplateVersionStatus::Retired, $version->fresh()->status);
    }

    public function test_the_only_draft_cannot_be_deleted_but_an_extra_draft_can(): void
    {
        $template = $this->makeTemplate();
        $firstVersion = $this->addMandatoryPoint($template);

        try {
            $this->versions->deleteDraft($firstVersion, $this->actor);
            $this->fail('La suppression de la seule version aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version', $exception->errors());
        }

        $this->versions->activate($firstVersion, $this->actor, 'Version initiale.');
        $draft = $this->versions->createFromActive($template->fresh(), $this->actor, 'Brouillon à supprimer.');

        $this->versions->deleteDraft($draft, $this->actor);

        $this->assertDatabaseMissing('quality_control_template_versions', ['id' => $draft->getKey()]);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'template_version',
            'event' => 'template_version_deleted',
        ]);
    }

    public function test_a_second_draft_cannot_be_created_while_one_exists(): void
    {
        $template = $this->makeTemplate();

        $this->expectException(ValidationException::class);

        $this->versions->createDraft($template, $this->actor);
    }

    protected function makeTemplate(): QualityControlTemplate
    {
        return $this->templates->create([
            'code' => 'TPL-'.uniqid(),
            'name' => 'Modèle de test',
            'control_type' => QualityControlType::Dimensional->value,
        ], $this->actor);
    }

    protected function addMandatoryPoint(QualityControlTemplate $template): QualityControlTemplateVersion
    {
        $version = $template->draftVersion();

        $this->points->create($version, [
            'label' => 'Longueur',
            'result_type' => QualityControlResultType::Numeric->value,
            'unit' => 'mm',
            'tolerance_min' => 10,
            'tolerance_max' => 20,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'is_mandatory' => true,
        ]);

        return $version->fresh();
    }
}
