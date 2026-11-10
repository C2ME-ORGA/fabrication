<?php

namespace Database\Factories;

use App\Enums\QualityControlType;
use App\Models\QualityControlTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityControlTemplate>
 */
class QualityControlTemplateFactory extends Factory
{
    protected $model = QualityControlTemplate::class;

    protected static int $codeSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => sprintf('CTL-%04d', static::$codeSequence++),
            'name' => 'Modèle '.fake()->words(3, true),
            'description' => null,
            'control_type' => QualityControlType::Dimensional,
            'is_active' => false,
            'current_version_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
        ]);
    }
}
