<?php

namespace App\Policies;

use App\Models\QualityControlTemplate;
use App\Models\User;

/**
 * Le référentiel qualité est géré par le responsable qualité et les
 * administrateurs. Le responsable atelier gère les exigences portées par les
 * gammes, mais pas le catalogue des modèles de contrôle.
 */
class QualityControlTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageQualityTemplates();
    }

    public function view(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates();
    }

    public function create(User $user): bool
    {
        return $user->canManageQualityTemplates();
    }

    public function update(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates();
    }

    /**
     * Un modèle référencé par une gamme ou déjà copié dans un OF ne peut pas
     * être supprimé : il doit être désactivé.
     */
    public function delete(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates()
            && ! $template->isUsedByRoutings()
            && ! $template->hasGeneratedControls();
    }

    public function activate(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates() && ! $template->isActive();
    }

    public function deactivate(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates() && $template->isActive();
    }

    public function manageVersions(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates();
    }

    public function managePoints(User $user, QualityControlTemplate $template): bool
    {
        return $user->canManageQualityTemplates();
    }
}
