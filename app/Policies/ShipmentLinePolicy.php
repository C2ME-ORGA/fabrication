<?php

namespace App\Policies;

use App\Models\ShipmentLine;
use App\Models\User;

class ShipmentLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isWorkshopSupervisor() || $user->isQualityManager();
    }

    public function view(User $user, ShipmentLine $line): bool
    {
        return $this->viewAny($user)
            && ($user->isAdmin() || $line->shipment?->workOrder?->isAccessibleBy($user) === true);
    }

    public function create(User $user, ShipmentLine $line): bool
    {
        return $this->mutate($user, $line);
    }

    public function update(User $user, ShipmentLine $line): bool
    {
        return $this->mutate($user, $line);
    }

    public function delete(User $user, ShipmentLine $line): bool
    {
        return $this->mutate($user, $line);
    }

    protected function mutate(User $user, ShipmentLine $line): bool
    {
        return ($user->isAdmin() || $user->isWorkshopSupervisor())
            && $line->shipment?->isEditable() === true;
    }
}
