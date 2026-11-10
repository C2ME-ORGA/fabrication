<?php

namespace Database\Factories;

use App\Enums\QualityResultDecision;
use App\Models\QualityControlResult;
use App\Models\QualityControlResultValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityControlResultValidation>
 */
class QualityControlResultValidationFactory extends Factory
{
    protected $model = QualityControlResultValidation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quality_control_result_id' => QualityControlResult::factory(),
            'decision' => QualityResultDecision::Validated,
            'validated_by' => User::factory(),
            'validated_at' => now(),
            'comment' => null,
            'created_at' => now(),
        ];
    }
}
