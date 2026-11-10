<?php

namespace App\Policies;

use App\Enums\QualityControlStatus;
use App\Models\User;
use App\Models\WorkOrderQualityControl;

/**
 * Habilitations des contrôles qualité générés.
 *
 * Tout utilisateur authentifié peut réaliser un contrôle sur un OF en
 * exécution ou en phase qualité. La validation est réservée au responsable
 * atelier, au responsable qualité et à l'administrateur. La ségrégation et le
 * rang de validation sont contrôlés dans le service, car ils dépendent des
 * données (contrôleur, validateurs déjà enregistrés).
 */
class QualityControlPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrderQualityControl $control): bool
    {
        $workOrder = $control->workOrder;

        if ($workOrder === null) {
            return false;
        }

        if ($workOrder->allowsOperationExecution()) {
            return true;
        }

        return $user->canViewQualityHistory();
    }

    public function recordResult(User $user, WorkOrderQualityControl $control): bool
    {
        return $user->canRecordQualityControls()
            && $control->workOrder?->allowsOperationExecution() === true
            && $control->allowsResultEntry();
    }

    public function validateResult(User $user, WorkOrderQualityControl $control): bool
    {
        return $user->canValidateQualityControls()
            && $control->workOrder?->allowsOperationExecution() === true
            && ! $control->isCancelled();
    }

    public function cancel(User $user, WorkOrderQualityControl $control): bool
    {
        return $user->canValidateQualityControls()
            && $control->workOrder?->allowsOperationExecution() === true
            && in_array($control->status, [
                QualityControlStatus::Pending,
                QualityControlStatus::InProgress,
            ], true);
    }

    /**
     * Ajout manuel d'un modèle de contrôle avant libération de l'OF.
     */
    public function generate(User $user): bool
    {
        return $user->canManageQualityTemplates();
    }
}
