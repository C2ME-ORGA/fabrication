<?php

namespace App\Policies;

use App\Enums\WorkshopOperationStatus;
use App\Models\User;
use App\Models\WorkOrderOperation;

/**
 * Protection des opérations générées dans un OF.
 *
 * L'affectation de gamme et la modification des opérations générées sont
 * réservées aux administrateurs et ne sont possibles qu'avant la libération
 * de l'OF. Les responsables atelier et administrateurs peuvent consulter et
 * corriger l'historique des temps, y compris après clôture ou annulation.
 */
class WorkOrderOperationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrderOperation $operation): bool
    {
        if ($operation->workOrder->allowsOperationExecution()) {
            return true;
        }

        return $user->canManageOperationTimes()
            && $operation->workOrder->status->isLocked();
    }

    public function update(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canMutate($user, $operation);
    }

    public function delete(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canMutate($user, $operation);
    }

    public function manageDependencies(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canMutate($user, $operation);
    }

    /**
     * Exécution : tout utilisateur authentifié peut démarrer, mettre en pause,
     * reprendre, bloquer ou terminer une opération d'un OF libéré.
     */
    public function start(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation);
    }

    public function pause(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation);
    }

    public function resume(User $user, WorkOrderOperation $operation): bool
    {
        if ($operation->status === WorkshopOperationStatus::Blocked) {
            return $this->canExecute($operation) && $user->canUnblockWorkshopOperations();
        }

        return $this->canExecute($operation);
    }

    public function block(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation);
    }

    public function complete(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation);
    }

    /**
     * Déblocage et annulation réservés au responsable atelier et aux
     * administrateurs.
     */
    public function unblock(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation) && $user->canUnblockWorkshopOperations();
    }

    public function cancel(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation) && $user->canUnblockWorkshopOperations();
    }

    /**
     * Chronométrage : tout opérateur authentifié peut démarrer un chrono sur
     * une opération en cours. L'arrêt est vérifié par le service pour garantir
     * que l'utilisateur arrête uniquement sa propre session.
     */
    public function startTimer(User $user, WorkOrderOperation $operation): bool
    {
        return $this->canExecute($operation);
    }

    public function stopTimer(User $user, WorkOrderOperation $operation): bool
    {
        return true;
    }

    /**
     * Saisie manuelle et correction réservées au responsable atelier et aux
     * administrateurs. La saisie manuelle reste limitée aux OF en exécution ;
     * la correction reste disponible sur l'historique clôturé ou annulé.
     */
    public function recordManualTime(User $user, WorkOrderOperation $operation): bool
    {
        return $user->canManageOperationTimes()
            && $this->canExecute($operation)
            && $operation->status !== WorkshopOperationStatus::Cancelled;
    }

    public function correctTime(User $user, WorkOrderOperation $operation): bool
    {
        return $user->canManageOperationTimes();
    }

    protected function canExecute(WorkOrderOperation $operation): bool
    {
        return $operation->workOrder->allowsOperationExecution();
    }

    protected function canMutate(User $user, WorkOrderOperation $operation): bool
    {
        // Les opérations générées font partie de la gamme de l'OF. Leur
        // modification suit la même règle que l'affectation de gamme :
        // administrateur uniquement, avant libération, hors statut final.
        return $user->isAdmin()
            && ! $operation->workOrder->isRoutingLocked()
            && ! $operation->status->isFinal();
    }
}
