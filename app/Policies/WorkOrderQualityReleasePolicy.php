<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrderQualityRelease;

/**
 * Consultation des preuves de libération qualité.
 *
 * La création est contrôlée par WorkOrderPolicy::releaseQuality, car elle
 * s'applique à un OF et non à une preuve existante.
 */
class WorkOrderQualityReleasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewQualityHistory();
    }

    public function view(User $user, WorkOrderQualityRelease $release): bool
    {
        if (! $user->canViewQualityHistory()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $release->workOrder?->isAccessibleBy($user) === true;
    }
}
