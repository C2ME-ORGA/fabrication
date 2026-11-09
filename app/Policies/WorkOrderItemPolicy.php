<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;

class WorkOrderItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, WorkOrderItem $item): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() && ! $workOrder->isLocked();
    }

    public function update(User $user, WorkOrderItem $item): bool
    {
        return $user->isAdmin()
            && ! $item->workOrder->isLocked()
            && ! $item->status->isFinal();
    }

    /**
     * Une pièce déjà produite ou rebutée ne peut pas être supprimée : elle
     * doit être annulée pour conserver l'historique de production.
     */
    public function delete(User $user, WorkOrderItem $item): bool
    {
        return $user->isAdmin()
            && ! $item->workOrder->isLocked()
            && ! $item->status->isFinal()
            && ! $item->hasRecordedProduction();
    }

    public function recordProduction(User $user, WorkOrderItem $item): bool
    {
        return $user->isAdmin()
            && ! $item->workOrder->isLocked()
            && ! $item->status->isFinal();
    }

    public function changeStatus(User $user, WorkOrderItem $item): bool
    {
        return $user->isAdmin()
            && ! $item->workOrder->isLocked()
            && ! $item->status->isFinal();
    }
}
