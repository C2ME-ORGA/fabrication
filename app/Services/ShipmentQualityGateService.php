<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

/**
 * Garde qualité de la validation d'expédition.
 *
 * Réutilise la preuve persistée du Lot 3 et la réévaluation FC-7 de phase
 * `shipment`, sans court-circuit `isLocked()`.
 */
class ShipmentQualityGateService
{
    public function __construct(
        protected QualityClosureEligibilityService $eligibility,
        protected WorkOrderQualityReleaseService $releases,
    ) {}

    public function assertShippable(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== WorkOrderStatus::Completed) {
            throw ValidationException::withMessages([
                'quality' => "Une expédition ne peut être validée que pour un OF terminé. Statut actuel : « {$workOrder->status->getLabel()} ».",
            ]);
        }

        $release = $this->releases->latestFor($workOrder);

        if ($release === null) {
            throw ValidationException::withMessages([
                'quality' => 'Aucune preuve de libération qualité éligible n’existe pour cet OF. Une re-libération explicite est requise.',
            ]);
        }

        if (! $release->eligible) {
            throw ValidationException::withMessages([
                'quality' => 'La dernière preuve de libération qualité n’est pas éligible.',
            ]);
        }

        $eligibility = $this->eligibility->evaluateForShipment($workOrder);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'quality' => $eligibility->message(),
            ]);
        }
    }
}
