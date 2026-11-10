<?php

namespace Database\Factories;

use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlTemplate;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkshopRoutingOperationQualityRequirement>
 */
class WorkshopRoutingOperationQualityRequirementFactory extends Factory
{
    protected $model = WorkshopRoutingOperationQualityRequirement::class;

    protected static int $positionSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workshop_routing_operation_id' => WorkshopRoutingOperation::factory(),
            'quality_control_template_id' => QualityControlTemplate::factory(),
            'scope' => QualityRequirementScope::Operation,
            'trigger' => QualityRequirementTrigger::OnCompletion,
            'is_mandatory' => true,
            'position' => static::$positionSequence++ * 10,
        ];
    }
}
