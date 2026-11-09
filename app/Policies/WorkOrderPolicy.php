<?php

namespace App\Policies;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Completed and cancelled work orders are frozen.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() && ! $workOrder->isLocked();
    }

    /**
     * Only drafts may be removed; other states carry history.
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin()
            && $workOrder->status === WorkOrderStatus::Draft
            && $workOrder->documents()->doesntExist()
            && $workOrder->documentSnapshots()->doesntExist()
            && $workOrder->bomImports()->doesntExist()
            && ! $workOrder->hasRecordedItemProduction();
    }

    /**
     * Explicit documentary re-review for a released work order after a new
     * approved revision. Completed/cancelled OFs are frozen.
     */
    public function reReviewDocuments(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() && in_array($workOrder->status, [
            WorkOrderStatus::Released,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::QualityCheck,
        ], true);
    }

    /**
     * Any non-final work order may attempt a status change; the exact
     * transition matrix is enforced by WorkOrderService.
     */
    public function transition(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() && ! $workOrder->isLocked();
    }

    /**
     * Only administrators may approve a work order for the engineering office.
     */
    public function approveBE(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin()
            && ! $workOrder->isLocked()
            && $workOrder->be_approved_by === null;
    }

    /**
     * Gestion de la nomenclature : création, import et actions de masse.
     * Les règles fines par pièce sont dans WorkOrderItemPolicy.
     */
    public function manageItems(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() && ! $workOrder->isLocked();
    }

    /**
     * Affectation ou remplacement de la gamme atelier d'un OF.
     * La gamme et ses opérations sont verrouillées après libération.
     */
    public function manageRouting(User $user, WorkOrder $workOrder): bool
    {
        return $user->canManageWorkshopRoutings() && $workOrder->isRoutingEditable();
    }
}
