<?php

namespace App\Services;

use App\Contracts\WorkOrderQualityReleaseSource;
use App\DTO\WorkOrderQualityReleaseData;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityRelease;

/**
 * Source réelle du visa Qualité du PDF : dernière preuve persistée.
 *
 * Aucun repli sur l'audit n'est effectué. Si la dernière preuve est absente
 * ou non éligible, le visa reste « non effectué ».
 */
class DatabaseWorkOrderQualityReleaseSource implements WorkOrderQualityReleaseSource
{
    public function latestFor(WorkOrder $workOrder): ?WorkOrderQualityReleaseData
    {
        $release = WorkOrderQualityRelease::query()
            ->where('work_order_id', $workOrder->getKey())
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        if ($release === null) {
            return null;
        }

        return new WorkOrderQualityReleaseData(
            id: (int) $release->getKey(),
            eligible: (bool) $release->eligible,
            requirementsPresent: (bool) $release->requirements_present,
            releasedBy: $release->releaser,
            releasedAt: $release->released_at?->toIso8601String(),
            reference: $release->reference(),
            snapshotHash: $release->snapshot_hash,
        );
    }
}
