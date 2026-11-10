<?php

namespace App\Policies;

use App\Models\NonConformityAttachment;
use App\Models\User;

/**
 * Téléchargement des preuves d'une non-conformité.
 */
class NonConformityAttachmentPolicy
{
    public function download(User $user, NonConformityAttachment $attachment): bool
    {
        return $attachment->nonConformity !== null
            && $user->can('view', $attachment->nonConformity);
    }
}
