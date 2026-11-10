<?php

namespace App\Services;

use App\Enums\NonConformitySeverity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Lecture de la politique de gravité et production du snapshot figé d'une NC.
 *
 * La configuration n'est jamais relue pour une NC existante : le service
 * copie la matrice au moment de la création ou d'une requalification
 * explicite.
 */
class QualitySeverityPolicyService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(NonConformitySeverity $severity): array
    {
        $config = config("quality.severities.{$severity->value}");

        if (! is_array($config)) {
            throw ValidationException::withMessages([
                'severity' => "La politique de gravité « {$severity->getLabel()} » n'est pas configurée.",
            ]);
        }

        foreach (['blocking', 'verification', 'counter_control'] as $flag) {
            if (! array_key_exists($flag, $config)) {
                throw ValidationException::withMessages([
                    'severity' => "La politique de gravité « {$severity->getLabel()} » est incomplète.",
                ]);
            }
        }

        $dueDays = (int) ($config['due_days'] ?? 0);

        if ($dueDays < 0) {
            throw ValidationException::withMessages([
                'severity' => "L'échéance de la politique de gravité est invalide.",
            ]);
        }

        return [
            'severity' => $severity->value,
            'blocking' => (bool) $config['blocking'],
            'verification_required' => (bool) $config['verification'],
            'counter_control_required' => (bool) $config['counter_control'],
            'due_days' => $dueDays,
            'config_version' => substr(hash('sha256', json_encode($config) ?: ''), 0, 12),
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function dueDateFor(array $snapshot, CarbonInterface $detectedAt): string
    {
        return Carbon::instance($detectedAt)
            ->copy()
            ->addDays(max(0, (int) ($snapshot['due_days'] ?? 0)))
            ->toDateString();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function requiresVerification(array $snapshot): bool
    {
        return (bool) ($snapshot['verification_required'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function requiresCounterControl(array $snapshot): bool
    {
        return (bool) ($snapshot['counter_control_required'] ?? false);
    }
}
