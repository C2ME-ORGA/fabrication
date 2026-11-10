<?php

namespace App\Policies;

use App\Models\QualityDeviation;
use App\Models\User;

/**
 * Dérogations qualité : demande, approbation, refus et révocation réservés
 * au responsable qualité et à l'administrateur.
 */
class QualityDeviationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canGrantQualityDeviations();
    }

    public function view(User $user, QualityDeviation $deviation): bool
    {
        return $user->canGrantQualityDeviations()
            || (int) $deviation->requested_by === (int) $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->canGrantQualityDeviations();
    }

    public function approve(User $user, QualityDeviation $deviation): bool
    {
        return $user->canApproveQualityDeviations()
            && $deviation->isPending()
            && (int) $deviation->requested_by !== (int) $user->getKey();
    }

    public function reject(User $user, QualityDeviation $deviation): bool
    {
        return $user->canApproveQualityDeviations()
            && $deviation->isPending();
    }

    public function revoke(User $user, QualityDeviation $deviation): bool
    {
        return $user->canRevokeQualityDeviations()
            && $deviation->isActive();
    }

    public function attachEvidence(User $user, QualityDeviation $deviation): bool
    {
        return $user->canGrantQualityDeviations()
            || (int) $deviation->requested_by === (int) $user->getKey();
    }

    public function downloadEvidence(User $user, QualityDeviation $deviation): bool
    {
        return $this->view($user, $deviation);
    }
}
