<?php

namespace App\DTO;

/**
 * Résultat structuré du calcul d'éligibilité qualité d'un OF.
 */
final class ClosureEligibility
{
    /**
     * @param  array<int, ClosureBlocker>  $blockers
     * @param  array<int, string>  $warnings
     * @param  array<int, int>  $controlIds
     * @param  array<int, int>  $nonConformityIds
     * @param  array<int, int>  $deviationIds
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $blockers,
        public readonly array $warnings,
        public readonly array $controlIds,
        public readonly array $nonConformityIds,
        public readonly array $deviationIds,
        public readonly string $evaluatedAt,
        public readonly string $phase,
    ) {}

    public function hasBlockers(): bool
    {
        return $this->blockers !== [];
    }

    public function isEligible(): bool
    {
        return $this->eligible;
    }

    public function message(): string
    {
        if ($this->blockers === []) {
            return 'L\'ordre de fabrication est éligible qualité.';
        }

        return implode(' ', array_values(array_unique(array_map(
            fn (ClosureBlocker $blocker): string => $blocker->message,
            $this->blockers,
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_eligible' => $this->eligible,
            'eligible' => $this->eligible,
            'blockers' => array_map(fn (ClosureBlocker $blocker): array => $blocker->toArray(), $this->blockers),
            'warnings' => $this->warnings,
            'control_ids' => $this->controlIds,
            'non_conformity_ids' => $this->nonConformityIds,
            'deviation_ids' => $this->deviationIds,
            'evaluated_at' => $this->evaluatedAt,
            'phase' => $this->phase,
        ];
    }
}
