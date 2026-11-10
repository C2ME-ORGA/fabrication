<?php

namespace Database\Factories;

use App\Enums\NonConformityStatus;
use App\Models\NonConformity;
use App\Models\NonConformityStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NonConformityStatusHistory>
 */
class NonConformityStatusHistoryFactory extends Factory
{
    protected $model = NonConformityStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'non_conformity_id' => NonConformity::factory(),
            'from_status' => null,
            'to_status' => NonConformityStatus::Open,
            'actor_id' => User::factory(),
            'comment' => 'Historique de test.',
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
