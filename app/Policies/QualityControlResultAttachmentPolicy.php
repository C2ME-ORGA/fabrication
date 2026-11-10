<?php

namespace App\Policies;

use App\Models\QualityControlResultAttachment;
use App\Models\User;

/**
 * Téléchargement des preuves d'un résultat de contrôle.
 *
 * L'autorisation dépend du contrôle parent : un utilisateur ne peut pas
 * contourner l'accès à l'OF en devinant l'identifiant d'une pièce jointe.
 */
class QualityControlResultAttachmentPolicy
{
    public function download(User $user, QualityControlResultAttachment $attachment): bool
    {
        $control = $attachment->result?->point?->control;

        return $control !== null && $user->can('view', $control);
    }
}
