<?php

namespace Database\Factories;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformityOrigin;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Models\NonConformity;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NonConformity>
 */
class NonConformityFactory extends Factory
{
    protected $model = NonConformity::class;

    protected static int $referenceSequence = 9001;

    public function configure(): static
    {
        return $this->afterCreating(function (NonConformity $nonConformity): void {
            if ($nonConformity->quality_control_result_id === null
                || $nonConformity->unique_result_id !== null) {
                return;
            }

            $nonConformity->forceFill([
                'unique_result_id' => $nonConformity->status === NonConformityStatus::Cancelled
                    ? null
                    : $nonConformity->quality_control_result_id,
            ])->saveQuietly();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = static::$referenceSequence++;
        $severity = NonConformitySeverity::Minor;
        $policy = config("quality.severities.{$severity->value}", []);

        return [
            'reference' => sprintf('NC-%d-%04d', now()->year, $number),
            'origin' => NonConformityOrigin::WorkOrder,
            'work_order_id' => WorkOrder::factory(),
            'work_order_number' => null,
            'work_order_item_id' => null,
            'work_order_operation_id' => null,
            'work_order_quality_control_id' => null,
            'quality_control_result_id' => null,
            'description' => 'Non-conformité de test suffisamment détaillée.',
            'criterion' => null,
            'severity' => $severity,
            'initial_severity' => $severity,
            'category' => NonConformityCategory::Process,
            'status' => NonConformityStatus::Open,
            'is_blocking' => (bool) ($policy['blocking'] ?? false),
            'verification_required' => (bool) ($policy['verification'] ?? false),
            'counter_control_required' => (bool) ($policy['counter_control'] ?? false),
            'severity_policy_snapshot' => [
                'severity' => $severity->value,
                'blocking' => (bool) ($policy['blocking'] ?? false),
                'verification_required' => (bool) ($policy['verification'] ?? false),
                'counter_control_required' => (bool) ($policy['counter_control'] ?? false),
                'due_days' => (int) ($policy['due_days'] ?? 30),
                'config_version' => 'test',
                'snapshot_at' => now()->toIso8601String(),
            ],
            'current_severity_policy_snapshot' => [
                'severity' => $severity->value,
                'blocking' => (bool) ($policy['blocking'] ?? false),
                'verification_required' => (bool) ($policy['verification'] ?? false),
                'counter_control_required' => (bool) ($policy['counter_control'] ?? false),
                'due_days' => (int) ($policy['due_days'] ?? 30),
                'config_version' => 'test',
                'snapshot_at' => now()->toIso8601String(),
            ],
            'detected_at' => now(),
            'detected_by' => User::factory(),
            'created_by' => User::factory(),
            'responsible_id' => null,
            'due_date' => now()->addDays(30)->toDateString(),
            'treatment_plan' => null,
            'immediate_action' => null,
            'containment_at' => null,
            'root_cause' => null,
            'root_cause_method' => null,
            'qualified_by' => null,
            'qualified_at' => null,
            'qualification_comment' => null,
            'taken_in_charge_at' => null,
            'treated_at' => null,
            'verification_due_at' => null,
            'closed_at' => null,
            'closed_by' => null,
            'closure_comment' => null,
        ];
    }

    public function severity(NonConformitySeverity $severity): static
    {
        return $this->state(function () use ($severity): array {
            $policy = config("quality.severities.{$severity->value}", []);

            return [
                'severity' => $severity,
                'initial_severity' => $severity,
                'is_blocking' => (bool) ($policy['blocking'] ?? false),
                'verification_required' => (bool) ($policy['verification'] ?? false),
                'counter_control_required' => (bool) ($policy['counter_control'] ?? false),
                'severity_policy_snapshot' => [
                    'severity' => $severity->value,
                    'blocking' => (bool) ($policy['blocking'] ?? false),
                    'verification_required' => (bool) ($policy['verification'] ?? false),
                    'counter_control_required' => (bool) ($policy['counter_control'] ?? false),
                    'due_days' => (int) ($policy['due_days'] ?? 30),
                    'config_version' => 'test',
                    'snapshot_at' => now()->toIso8601String(),
                ],
                'current_severity_policy_snapshot' => [
                    'severity' => $severity->value,
                    'blocking' => (bool) ($policy['blocking'] ?? false),
                    'verification_required' => (bool) ($policy['verification'] ?? false),
                    'counter_control_required' => (bool) ($policy['counter_control'] ?? false),
                    'due_days' => (int) ($policy['due_days'] ?? 30),
                    'config_version' => 'test',
                    'snapshot_at' => now()->toIso8601String(),
                ],
            ];
        });
    }

    public function status(NonConformityStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
