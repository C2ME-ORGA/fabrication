<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * Consultation de l'éligibilité qualité d'un OF.
 */
class QualityClosurePolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->canViewQualityEligibility();
    }
}
