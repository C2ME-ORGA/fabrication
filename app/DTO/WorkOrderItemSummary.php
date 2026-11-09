<?php

namespace App\DTO;

/**
 * Récapitulatif de production d'une nomenclature.
 *
 * Les quantités acceptée et restante respectent les règles du domaine :
 * accepted = produced - rejected, remaining = max(planned - accepted, 0).
 */
readonly class WorkOrderItemSummary
{
    public function __construct(
        public int $planned,
        public int $produced,
        public int $accepted,
        public int $rejected,
        public int $remaining,
        public float $totalWeightKg,
    ) {}
}
