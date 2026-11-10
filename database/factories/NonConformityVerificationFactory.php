<?php

namespace Database\Factories;

use App\Enums\NonConformityVerificationResult;
use App\Enums\NonConformityVerificationType;
use App\Models\NonConformity;
use App\Models\NonConformityVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NonConformityVerification>
 */
class NonConformityVerificationFactory extends Factory
{
    protected $model = NonConformityVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'non_conformity_id' => NonConformity::factory(),
            'type' => NonConformityVerificationType::Effectiveness,
            'performed_by' => User::factory(),
            'performed_at' => now(),
            'result' => NonConformityVerificationResult::Conforming,
            'comment' => 'Vérification de test.',
            'counter_control_id' => null,
            'created_at' => now(),
        ];
    }
}
