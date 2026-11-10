<?php

namespace App\Policies;

use App\Enums\NonConformityStatus;
use App\Models\NonConformity;
use App\Models\User;

/**
 * Habilitations des non-conformités.
 *
 * La séparation fine (gravité, déclarant, vérificateur) est également
 * contrôlée dans les services métier.
 */
class NonConformityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NonConformity $nonConformity): bool
    {
        if ((int) $nonConformity->created_by === (int) $user->getKey()
            || (int) $nonConformity->detected_by === (int) $user->getKey()) {
            return true;
        }

        $workOrder = $nonConformity->workOrder;

        if ($workOrder === null) {
            return $user->canManageNonConformities();
        }

        return $workOrder->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return $user->canReportNonConformity();
    }

    public function update(User $user, NonConformity $nonConformity): bool
    {
        return $user->canManageNonConformities()
            && ! $nonConformity->isFinal();
    }

    public function qualify(User $user, NonConformity $nonConformity): bool
    {
        return $user->canManageNonConformities()
            && ! $nonConformity->isFinal();
    }

    public function assign(User $user, NonConformity $nonConformity): bool
    {
        return $user->canManageNonConformities()
            && ! $nonConformity->isFinal();
    }

    public function start(User $user, NonConformity $nonConformity): bool
    {
        return ! $nonConformity->isFinal()
            && (
                $user->canManageNonConformities()
                || (int) $nonConformity->responsible_id === (int) $user->getKey()
            );
    }

    public function submitForVerification(User $user, NonConformity $nonConformity): bool
    {
        return $user->canManageNonConformities()
            && $nonConformity->status === NonConformityStatus::InProgress;
    }

    public function close(User $user, NonConformity $nonConformity): bool
    {
        if ($nonConformity->status !== NonConformityStatus::PendingVerification) {
            return false;
        }

        return $nonConformity->isMajorOrCritical()
            ? $user->canCloseMajorNonConformities()
            : $user->canCloseMinorNonConformities();
    }

    public function reopen(User $user, NonConformity $nonConformity): bool
    {
        return $user->canReopenNonConformities()
            && $nonConformity->isClosed();
    }

    public function cancel(User $user, NonConformity $nonConformity): bool
    {
        return $user->canManageNonConformities()
            && in_array($nonConformity->status, [
                NonConformityStatus::Open,
                NonConformityStatus::InProgress,
            ], true);
    }

    public function verify(User $user, NonConformity $nonConformity): bool
    {
        if ($nonConformity->status !== NonConformityStatus::PendingVerification) {
            return false;
        }

        return $nonConformity->isMajorOrCritical()
            ? $user->canVerifyNonConformities()
            : $user->canManageNonConformities();
    }

    public function attachEvidence(User $user, NonConformity $nonConformity): bool
    {
        if ($nonConformity->isFinal()) {
            return $user->canManageNonConformities();
        }

        return $user->canManageNonConformities()
            || (int) $nonConformity->created_by === (int) $user->getKey()
            || (int) $nonConformity->detected_by === (int) $user->getKey();
    }

    public function manageCorrectiveActions(User $user, NonConformity $nonConformity): bool
    {
        return $user->canManageNonConformities()
            && ! $nonConformity->isFinal();
    }
}
