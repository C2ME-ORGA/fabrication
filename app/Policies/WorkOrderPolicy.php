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
     * Génération du PDF d'OF : réservée aux profils habilités du périmètre
     * initial. Le responsable atelier ne peut pas générer de document.
     */
    public function generatePdf(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin();
    }

    /**
     * Consultation et téléchargement du PDF archivé.
     *
     * Le responsable atelier et le responsable qualité y accèdent si l'OF
     * leur est accessible. Cette capacité ne donne aucun droit d'écriture.
     */
    public function viewPdf(User $user, WorkOrder $workOrder): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isWorkshopSupervisor() && ! $user->isQualityManager()) {
            return false;
        }

        return $workOrder->isAccessibleBy($user);
    }

    /**
     * Libération qualité : preuve persistée réservée aux profils habilités
     * à gérer la qualité. Le responsable atelier ne crée pas cette preuve.
     */
    public function releaseQuality(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() || $user->isQualityManager();
    }

    /**
     * Décision P7 sur une révision documentaire : responsable qualité
     * uniquement. L'administrateur et le responsable atelier ne décident pas
     * à sa place.
     */
    public function decideDocumentReview(User $user, WorkOrder $workOrder): bool
    {
        return $user->isQualityManager();
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
     * L'affectation est réservée aux administrateurs ; le responsable atelier
     * conserve la gestion du catalogue des gammes.
     */
    public function manageRouting(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin() && $workOrder->isRoutingEditable();
    }
}
