<?php

namespace App\Policies;

use App\Models\ShipmentPackage;
use App\Models\User;

class ShipmentPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isWorkshopSupervisor() || $user->isQualityManager();
    }

    public function view(User $user, ShipmentPackage $package): bool
    {
        return $this->viewAny($user)
            && ($user->isAdmin() || $package->shipment?->workOrder?->isAccessibleBy($user) === true);
    }

    public function create(User $user, ShipmentPackage $package): bool
    {
        return $this->mutate($user, $package);
    }

    public function update(User $user, ShipmentPackage $package): bool
    {
        return $this->mutate($user, $package);
    }

    public function delete(User $user, ShipmentPackage $package): bool
    {
        return $this->mutate($user, $package);
    }

    protected function mutate(User $user, ShipmentPackage $package): bool
    {
        return ($user->isAdmin() || $user->isWorkshopSupervisor())
            && $package->shipment?->isEditable() === true;
    }
}
