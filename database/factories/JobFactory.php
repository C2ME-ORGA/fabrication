<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => sprintf('AFF-%d-%04d', now()->year, fake()->unique()->numberBetween(1, 9999)),
            'client_name' => fake()->company(),
            'name' => 'Chantier '.fake()->city(),
            'site_address' => fake()->streetAddress().', '.fake()->postcode().' '.fake()->city(),
            'status' => JobStatus::Draft,
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => JobStatus::Active]);
    }

    public function onHold(): static
    {
        return $this->state(fn (): array => ['status' => JobStatus::OnHold]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => JobStatus::Closed]);
    }
}
