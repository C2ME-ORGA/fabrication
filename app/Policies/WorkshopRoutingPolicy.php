<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkshopRouting;

/**
 * La gestion des gammes est réservée aux responsables atelier et aux
 * administrateurs. Un opérateur standard ne peut ni créer, ni modifier, ni
 * activer une gamme.
 *
 * La suppression physique est en plus limitée aux gammes jamais utilisées ;
 * une gamme historique doit être désactivée.
 */
class WorkshopRoutingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageWorkshopRoutings() || $user->canManageQualityRequirements();
    }

    public function view(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings() || $user->canManageQualityRequirements();
    }

    public function create(User $user): bool
    {
        return $user->canManageWorkshopRoutings();
    }

    public function update(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings();
    }

    public function delete(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings()
            && ! $routing->isUsedByWorkOrders()
            && ! $routing->hasGeneratedOperations();
    }

    public function activate(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings() && ! $routing->isActive();
    }

    public function deactivate(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings() && $routing->isActive();
    }

    public function manageOperations(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings();
    }

    public function manageDependencies(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageWorkshopRoutings();
    }

    /**
     * Les exigences qualité d'une gamme sont gérées par le responsable
     * atelier (catalogue), le responsable qualité et l'administrateur.
     */
    public function manageQualityRequirements(User $user, WorkshopRouting $routing): bool
    {
        return $user->canManageQualityRequirements();
    }
}
