<?php

namespace Database\Factories;

use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkshopRoutingOperation>
 */
class WorkshopRoutingOperationFactory extends Factory
{
    protected $model = WorkshopRoutingOperation::class;

    protected static int $codeSequence = 1;

    protected static int $positionSequence = 10;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workshop_routing_id' => WorkshopRouting::factory(),
            'code' => sprintf('OP-%04d', static::$codeSequence++),
            'name' => 'Opération '.fake()->words(2, true),
            'instructions' => null,
            'position' => static::$positionSequence++,
            'estimated_duration_minutes' => fake()->numberBetween(5, 120),
            'is_mandatory' => true,
        ];
    }

    public function forRouting(WorkshopRouting $routing): static
    {
        return $this->state(fn (): array => [
            'workshop_routing_id' => $routing->getKey(),
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn (): array => [
            'is_mandatory' => false,
        ]);
    }
}
