<?php

namespace Tests\Feature;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
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
use RuntimeException;
use Tests\TestCase;

class QualityControlPointTest extends TestCase
{
    use RefreshDatabase;

    protected QualityControlPointService $points;

    protected QualityControlTemplateService $templates;

    protected QualityControlTemplateVersionService $versions;

    protected User $actor;

    protected QualityControlTemplate $template;

    protected QualityControlTemplateVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->points = app(QualityControlPointService::class);
        $this->templates = app(QualityControlTemplateService::class);
        $this->versions = app(QualityControlTemplateVersionService::class);
        $this->actor = User::factory()->qualityManager()->create();

        $this->template = $this->templates->create([
            'code' => 'DIM-'.uniqid(),
            'name' => 'Dimensionnel',
            'control_type' => QualityControlType::Dimensional->value,
        ], $this->actor);

        $this->version = $this->template->draftVersion();
    }

    public function test_a_numeric_point_requires_a_unit(): void
    {
        try {
            $this->points->create($this->version, [
                'label' => 'Longueur',
                'result_type' => QualityControlResultType::Numeric->value,
                'sampling_mode' => QualityControlSamplingMode::All->value,
            ]);

            $this->fail('Un point numérique sans unité aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }
    }

    public function test_tolerance_bounds_must_be_ordered(): void
    {
        $this->expectException(ValidationException::class);

        $this->points->create($this->version, [
            'label' => 'Longueur',
            'result_type' => QualityControlResultType::Numeric->value,
            'unit' => 'mm',
            'tolerance_min' => 20,
            'tolerance_max' => 10,
            'sampling_mode' => QualityControlSamplingMode::All->value,
        ]);
    }

    public function test_tolerances_are_removed_for_non_numeric_points(): void
    {
        $point = $this->points->create($this->version, [
            'label' => 'Aspect',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'tolerance_min' => 1,
            'tolerance_max' => 2,
            'unit' => 'mm',
        ]);

        $this->assertNull($point->tolerance_min);
        $this->assertNull($point->tolerance_max);
        $this->assertNull($point->unit);
    }

    public function test_a_choice_point_requires_at_least_one_conformant_option(): void
    {
        try {
            $this->points->create($this->version, [
                'label' => 'Couleur',
                'result_type' => QualityControlResultType::Choice->value,
                'sampling_mode' => QualityControlSamplingMode::All->value,
                'options' => [
                    ['value' => 'rouge', 'label' => 'Rouge', 'conformant' => false],
                    ['value' => 'bleu', 'label' => 'Bleu', 'conformant' => false],
                ],
            ]);

            $this->fail('Une liste sans option conforme aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }
    }

    public function test_choice_option_values_must_be_unique(): void
    {
        $this->expectException(ValidationException::class);

        $this->points->create($this->version, [
            'label' => 'Couleur',
            'result_type' => QualityControlResultType::Choice->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'options' => [
                ['value' => 'ok', 'label' => 'OK', 'conformant' => true],
                ['value' => 'ok', 'label' => 'Doublon', 'conformant' => false],
            ],
        ]);
    }

    public function test_sampling_configuration_is_normalized(): void
    {
        $all = $this->points->create($this->version, [
            'label' => 'Toutes',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'sampling_every' => 5,
            'required_results' => 9,
        ]);

        $this->assertNull($all->sampling_every);
        $this->assertSame(1, $all->required_results);

        $every = $this->points->create($this->version->fresh(), [
            'label' => 'Une sur cinq',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::EveryN->value,
            'sampling_every' => 5,
            'required_results' => 9,
        ]);

        $this->assertSame(5, $every->sampling_every);
        $this->assertSame(1, $every->required_results);

        $fixed = $this->points->create($this->version->fresh(), [
            'label' => 'Trois pièces',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::Fixed->value,
            'required_results' => 3,
        ]);

        $this->assertNull($fixed->sampling_every);
        $this->assertSame(3, $fixed->required_results);
    }

    public function test_frequency_mode_requires_a_frequency_of_at_least_two(): void
    {
        $this->expectException(ValidationException::class);

        $this->points->create($this->version, [
            'label' => 'Fréquence invalide',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::EveryN->value,
            'sampling_every' => 1,
        ]);
    }

    public function test_positions_are_unique_and_normalized(): void
    {
        $first = $this->points->create($this->version, [
            'label' => 'Premier',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
        ]);

        $second = $this->points->create($this->version->fresh(), [
            'label' => 'Deuxième',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
        ]);

        $this->assertSame(10, $first->fresh()->position);
        $this->assertSame(20, $second->fresh()->position);

        try {
            $this->points->create($this->version->fresh(), [
                'label' => 'Doublon',
                'result_type' => QualityControlResultType::Conformity->value,
                'sampling_mode' => QualityControlSamplingMode::All->value,
                'position' => 10,
            ]);

            $this->fail('Une position dupliquée aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('position', $exception->errors());
        }
    }

    public function test_deleting_a_point_normalizes_the_remaining_positions(): void
    {
        $first = $this->makePoint('Un');
        $second = $this->makePoint('Deux');
        $third = $this->makePoint('Trois');

        $this->points->delete($second);

        $this->assertSame(10, $first->fresh()->position);
        $this->assertSame(20, $third->fresh()->position);
        $this->assertSame(2, $this->version->points()->count());
    }

    public function test_reordering_points_rewrites_positions(): void
    {
        $first = $this->makePoint('Un');
        $second = $this->makePoint('Deux');
        $third = $this->makePoint('Trois');

        $this->points->reorder($this->version->fresh(), [
            $third->getKey(),
            $first->getKey(),
            $second->getKey(),
        ]);

        $this->assertSame(10, $third->fresh()->position);
        $this->assertSame(20, $first->fresh()->position);
        $this->assertSame(30, $second->fresh()->position);
    }

    public function test_points_of_an_active_version_are_immutable(): void
    {
        $point = $this->makePoint('Longueur', QualityControlResultType::Conformity);

        $this->versions->activate($this->version->fresh(), $this->actor, 'Version initiale.');

        $this->expectException(RuntimeException::class);

        $point->fresh()->update(['label' => 'Modifié']);
    }

    public function test_points_of_an_active_version_cannot_be_deleted(): void
    {
        $point = $this->makePoint('Aspect', QualityControlResultType::Conformity);

        $this->versions->activate($this->version->fresh(), $this->actor, 'Version initiale.');

        $this->expectException(RuntimeException::class);

        $point->fresh()->delete();
    }

    public function test_point_mutations_are_audited(): void
    {
        $point = $this->makePoint('Audité');

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control_point',
            'subject_id' => $point->getKey(),
            'event' => 'point_added',
        ]);

        $this->points->update($point, ['label' => 'Audité modifié']);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control_point',
            'subject_id' => $point->getKey(),
            'event' => 'point_updated',
        ]);

        $this->points->delete($point->fresh());

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control_point',
            'subject_id' => $point->getKey(),
            'event' => 'point_removed',
        ]);
    }

    protected function makePoint(
        string $label,
        QualityControlResultType $type = QualityControlResultType::Conformity,
    ): QualityControlPoint {
        return $this->points->create($this->version->fresh(), [
            'label' => $label,
            'result_type' => $type->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'is_mandatory' => true,
        ]);
    }
}
