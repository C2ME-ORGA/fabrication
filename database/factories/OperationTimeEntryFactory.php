<?php

namespace Database\Factories;

use App\Enums\OperationTimeEntryType;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrderOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationTimeEntry>
 */
class OperationTimeEntryFactory extends Factory
{
    protected $model = OperationTimeEntry::class;

    /**
     * Par défaut, une session clôturée : créer plusieurs sessions pour le
     * même opérateur ne viole pas la contrainte d'unicité des sessions
     * actives. Utiliser active() pour une session en cours.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subHours(2);

        return [
            'work_order_operation_id' => WorkOrderOperation::factory(),
            'user_id' => User::factory(),
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHour(),
            'duration_seconds' => 3600,
            'entry_type' => OperationTimeEntryType::Timer,
            'created_by' => null,
            'corrected_by' => null,
            'correction_reason' => null,
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (): array => [
            'entry_type' => OperationTimeEntryType::Manual,
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn (): array => [
            'entry_type' => OperationTimeEntryType::Adjustment,
        ]);
    }

    public function forOperation(WorkOrderOperation $operation): static
    {
        return $this->state(fn (): array => [
            'work_order_operation_id' => $operation->getKey(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
        ]);
    }
}
