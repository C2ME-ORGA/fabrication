<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
            'is_workshop_supervisor' => false,
            'is_quality_manager' => false,
        ];
    }

    /**
     * User explicitly allowed to access the admin panel.
     */
    public function admin(): static
    {
        return $this->state(fn (): array => [
            'is_admin' => true,
        ]);
    }

    /**
     * Workshop supervisor without any administrator access.
     */
    public function workshopSupervisor(): static
    {
        return $this->state(fn (): array => [
            'is_workshop_supervisor' => true,
            'is_admin' => false,
        ]);
    }

    /**
     * Quality manager without any administrator access.
     */
    public function qualityManager(): static
    {
        return $this->state(fn (): array => [
            'is_quality_manager' => true,
            'is_admin' => false,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
