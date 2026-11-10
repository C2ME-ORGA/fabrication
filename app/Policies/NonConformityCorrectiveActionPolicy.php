<?php

namespace App\Policies;

use App\Models\NonConformityCorrectiveAction;
use App\Models\User;

/**
 * Habilitations des actions correctives.
 */
class NonConformityCorrectiveActionPolicy
{
    public function view(User $user, NonConformityCorrectiveAction $action): bool
    {
        return $user->can('view', $action->nonConformity);
    }

    public function update(User $user, NonConformityCorrectiveAction $action): bool
    {
        return $user->can('manageCorrectiveActions', $action->nonConformity);
    }

    public function complete(User $user, NonConformityCorrectiveAction $action): bool
    {
        return $this->update($user, $action);
    }

    public function verify(User $user, NonConformityCorrectiveAction $action): bool
    {
        return $user->canVerifyNonConformities()
            && ! $action->nonConformity->isFinal();
    }

    public function delete(User $user, NonConformityCorrectiveAction $action): bool
    {
        return false;
    }
}
