<?php

namespace App\Contracts;

use App\DTO\WorkOrderQualityReleaseData;
use App\Models\WorkOrder;

/**
 * Source de lecture de la preuve de libération qualité.
 *
 * Le Lot 2 ne crée pas encore la table work_order_quality_releases. Cette
 * interface permet au PDF de ne jamais fabriquer de visa qualité : la
 * liaison par défaut retourne null. Le Lot 3 remplacera l'implémentation
 * par le service qualité réel.
 */
interface WorkOrderQualityReleaseSource
{
    public function latestFor(WorkOrder $workOrder): ?WorkOrderQualityReleaseData;
}
