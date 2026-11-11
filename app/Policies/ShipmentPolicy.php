<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isWorkshopSupervisor() || $user->isQualityManager();
    }

    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isWorkshopSupervisor() && ! $user->isQualityManager()) {
            return false;
        }

        return $shipment->workOrder?->isAccessibleBy($user) === true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isWorkshopSupervisor();
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return ($user->isAdmin() || $user->isWorkshopSupervisor())
            && $shipment->isEditable();
    }

    public function prepare(User $user, Shipment $shipment): bool
    {
        return $this->update($user, $shipment);
    }

    public function reopen(User $user, Shipment $shipment): bool
    {
        return ($user->isAdmin() || $user->isWorkshopSupervisor())
            && $shipment->canReopen();
    }

    /**
     * Validation définitive : administrateur uniquement. Le statut et les
     * préconditions métier sont contrôlés dans ShipmentValidationService afin
     * qu'un refus laisse une trace d'audit.
     */
    public function validate(User $user, Shipment $shipment): bool
    {
        return $user->isAdmin();
    }

    /**
     * Départ effectif : administrateur uniquement, après validation.
     */
    public function ship(User $user, Shipment $shipment): bool
    {
        return $user->isAdmin() && $shipment->canBeShipped();
    }

    /**
     * Annulation contrôlée, réservée à l'administrateur.
     */
    public function cancel(User $user, Shipment $shipment): bool
    {
        return $user->isAdmin() && $shipment->canBeCancelled();
    }

    /**
     * Aucune suppression d'expédition : l'historique est conservé.
     */
    public function delete(User $user, Shipment $shipment): bool
    {
        return false;
    }
}
