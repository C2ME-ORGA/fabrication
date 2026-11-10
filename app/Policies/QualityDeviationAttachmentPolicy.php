<?php

namespace App\Policies;

use App\Models\QualityDeviationAttachment;
use App\Models\User;

/**
 * Téléchargement des preuves d'une dérogation qualité.
 */
class QualityDeviationAttachmentPolicy
{
    public function download(User $user, QualityDeviationAttachment $attachment): bool
    {
        return $attachment->deviation !== null
            && $user->can('view', $attachment->deviation);
    }
}
