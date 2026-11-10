<?php

namespace Database\Factories;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderQualityControlPoint>
 */
class WorkOrderQualityControlPointFactory extends Factory
{
    protected $model = WorkOrderQualityControlPoint::class;

    protected static int $positionSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_quality_control_id' => WorkOrderQualityControl::factory(),
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
            'sampled_item_ids' => [],
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

    public function critical(): static
    {
        return $this->state(fn (): array => ['is_critical' => true]);
    }
}
