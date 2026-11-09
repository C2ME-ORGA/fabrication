<?php

namespace Database\Factories;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityControlPoint>
 */
class QualityControlPointFactory extends Factory
{
    protected $model = QualityControlPoint::class;

    protected static int $positionSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quality_control_template_version_id' => QualityControlTemplateVersion::factory(),
            'position' => static::$positionSequence++ * 10,
            'label' => 'Point '.fake()->words(2, true),
            'instructions' => null,
            'result_type' => QualityControlResultType::Conformity,
            'unit' => null,
            'tolerance_min' => null,
            'tolerance_max' => null,
            'is_mandatory' => true,
            'is_critical' => false,
            'allow_deviation' => false,
            'sampling_mode' => QualityControlSamplingMode::All,
            'sampling_every' => null,
            'required_results' => 1,
            'options' => null,
        ];
    }

    public function numeric(?string $unit = 'mm', ?float $min = null, ?float $max = null): static
    {
        return $this->state(fn (): array => [
            'result_type' => QualityControlResultType::Numeric,
            'unit' => $unit,
            'tolerance_min' => $min,
            'tolerance_max' => $max,
        ]);
    }

    public function fixed(int $count): static
    {
        return $this->state(fn (): array => [
            'sampling_mode' => QualityControlSamplingMode::Fixed,
            'required_results' => $count,
        ]);
    }

    public function everyN(int $every): static
    {
        return $this->state(fn (): array => [
            'sampling_mode' => QualityControlSamplingMode::EveryN,
            'sampling_every' => $every,
        ]);
    }
}
