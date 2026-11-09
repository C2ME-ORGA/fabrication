<?php

namespace Database\Factories;

use App\Models\WorkshopRouting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkshopRouting>
 */
class WorkshopRoutingFactory extends Factory
{
    protected $model = WorkshopRouting::class;

    protected static int $codeSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => sprintf('GAM-%04d', static::$codeSequence++),
            'name' => 'Gamme '.fake()->words(3, true),
            'description' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
