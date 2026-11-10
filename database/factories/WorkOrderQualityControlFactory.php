<?php

namespace Database\Factories;

use App\Enums\QualityControlStatus;
use App\Enums\QualityControlType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderQualityControl>
 */
class WorkOrderQualityControlFactory extends Factory
{
    protected $model = WorkOrderQualityControl::class;

    protected static int $positionSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'work_order_operation_id' => null,
            'work_order_item_id' => null,
            'source_requirement_id' => null,
            'source_type' => 'manual',
            'template_code' => 'TPL-'.fake()->unique()->numerify('####'),
            'template_name' => 'Modèle de contrôle '.fake()->words(2, true),
            'template_version_number' => 1,
            'control_type' => QualityControlType::Visual,
            'scope' => QualityRequirementScope::Operation,
            'trigger' => QualityRequirementTrigger::OnCompletion,
            'is_mandatory' => true,
            'allow_deviation' => false,
            'position' => static::$positionSequence++ * 10,
            'status' => QualityControlStatus::Pending,
            'required_by_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'snapshot_metadata' => null,
        ];
    }

    public function status(QualityControlStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
