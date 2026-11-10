<?php

namespace Database\Factories;

use App\Enums\CorrectiveActionStatus;
use App\Models\NonConformity;
use App\Models\NonConformityCorrectiveAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NonConformityCorrectiveAction>
 */
class NonConformityCorrectiveActionFactory extends Factory
{
    protected $model = NonConformityCorrectiveAction::class;

    protected static int $positionSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'non_conformity_id' => NonConformity::factory(),
            'position' => static::$positionSequence++ * 10,
            'description' => 'Action corrective de test.',
            'responsible_id' => null,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => CorrectiveActionStatus::Planned,
            'completed_at' => null,
            'completed_by' => null,
            'evidence_comment' => null,
            'verification_required' => true,
        ];
    }
}
