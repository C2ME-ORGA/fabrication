<?php

namespace Database\Factories;

use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControlPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityControlResult>
 */
class QualityControlResultFactory extends Factory
{
    protected $model = QualityControlResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_quality_control_point_id' => WorkOrderQualityControlPoint::factory(),
            'work_order_id' => WorkOrder::factory(),
            'work_order_item_id' => null,
            'performed_by' => User::factory(),
            'performed_at' => now(),
            'value_boolean' => true,
            'value_numeric' => null,
            'value_text' => null,
            'value_choice' => null,
            'unit_snapshot' => null,
            'tolerance_min_snapshot' => null,
            'tolerance_max_snapshot' => null,
            'is_conform' => true,
            'evaluation_detail' => null,
            'comment' => null,
            'supersedes_result_id' => null,
            'recorded_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
