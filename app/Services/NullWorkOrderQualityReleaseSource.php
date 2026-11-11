<?php

namespace App\Services;

use App\Contracts\WorkOrderQualityReleaseSource;
use App\DTO\WorkOrderQualityReleaseData;
use App\Models\WorkOrder;

/**
 * Implémentation transitoire du Lot 2.
 *
 * Aucune preuve de libération qualité n'existe encore dans le schéma. Le PDF
 * doit donc afficher un visa Qualité non effectué, jamais un faux visa.
 */
class NullWorkOrderQualityReleaseSource implements WorkOrderQualityReleaseSource
{
    public function latestFor(WorkOrder $workOrder): ?WorkOrderQualityReleaseData
    {
        return null;
    }
}
