<?php

namespace App\Policies;

use App\Models\QualityControlPoint;
use App\Models\User;

/**
 * Les points de contrôle ne sont modifiables que dans une version brouillon.
 * La règle d'immutabilité est également garantie par le modèle et le service.
 */
class QualityControlPointPolicy
{
    public function view(User $user, QualityControlPoint $point): bool
    {
        return $user->canManageQualityTemplates();
    }

    public function create(User $user): bool
    {
        return $user->canManageQualityTemplates();
    }

    public function update(User $user, QualityControlPoint $point): bool
    {
        return $user->canManageQualityTemplates() && $point->isEditable();
    }

    public function delete(User $user, QualityControlPoint $point): bool
    {
        return $user->canManageQualityTemplates() && $point->isEditable();
    }
}
