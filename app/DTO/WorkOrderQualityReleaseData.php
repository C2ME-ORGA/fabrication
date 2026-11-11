<?php

namespace App\DTO;

use App\Models\User;

/**
 * Preuve de libération qualité lue pour le visa du PDF.
 *
 * Cette donnée est produite par la source qualité (Lot 3). Le Lot 2 ne crée
 * aucune preuve : l'implémentation par défaut retourne null.
 */
final readonly class WorkOrderQualityReleaseData
{
    public function __construct(
        public int $id,
        public bool $eligible,
        public bool $requirementsPresent,
        public ?User $releasedBy,
        public ?string $releasedAt,
        public ?string $reference,
        public ?string $snapshotHash,
    ) {}
}
