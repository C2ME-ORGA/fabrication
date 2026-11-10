<?php

namespace Database\Factories;

use App\Models\QualityDeviation;
use App\Models\QualityDeviationTarget;
use App\Models\WorkOrderQualityControl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityDeviationTarget>
 */
class QualityDeviationTargetFactory extends Factory
{
    protected $model = QualityDeviationTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quality_deviation_id' => QualityDeviation::factory(),
            'work_order_quality_control_id' => WorkOrderQualityControl::factory(),
            'work_order_quality_control_point_id' => null,
            'non_conformity_id' => null,
        ];
    }
}
