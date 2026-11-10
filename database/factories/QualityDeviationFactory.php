<?php

namespace Database\Factories;

use App\Enums\QualityDeviationScope;
use App\Enums\QualityDeviationStatus;
use App\Models\QualityDeviation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityDeviation>
 */
class QualityDeviationFactory extends Factory
{
    protected $model = QualityDeviation::class;

    protected static int $referenceSequence = 9001;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => sprintf('DER-%d-%04d', now()->year, static::$referenceSequence++),
            'work_order_id' => WorkOrder::factory(),
            'work_order_number' => null,
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'granted_by' => null,
            'granted_at' => null,
            'second_approver_id' => null,
            'second_approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'reason' => 'Motif de dérogation de test suffisamment détaillé.',
            'description' => null,
            'scope' => QualityDeviationScope::Control,
            'status' => QualityDeviationStatus::Pending,
            'is_critical' => false,
            'expires_at' => null,
            'revoked_by' => null,
            'revoked_at' => null,
            'revoke_reason' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => QualityDeviationStatus::Pending]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => QualityDeviationStatus::Active,
            'granted_by' => User::factory(),
            'granted_at' => now(),
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (): array => ['is_critical' => true]);
    }
}
