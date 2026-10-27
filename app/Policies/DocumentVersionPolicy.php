<?php

namespace App\Policies;

use App\Enums\DocumentVersionStatus;
use App\Models\DocumentVersion;
use App\Models\User;

class DocumentVersionPolicy
{
    public function view(User $user, DocumentVersion $version): bool
    {
        return $user->isAdmin();
    }

    public function download(User $user, DocumentVersion $version): bool
    {
        // Archived documents and superseded revisions remain readable for
        // history, but can no longer receive versions.
        return $user->isAdmin();
    }

    public function submit(User $user, DocumentVersion $version): bool
    {
        return $user->isAdmin()
            && $version->status === DocumentVersionStatus::Draft
            && ! $version->document->isArchived();
    }

    public function approve(User $user, DocumentVersion $version): bool
    {
        return $user->isAdmin()
            && $version->status === DocumentVersionStatus::PendingValidation
            && ! $version->document->isArchived();
    }

    public function reject(User $user, DocumentVersion $version): bool
    {
        // A pending version can still be refused after its document was
        // archived, so no pending revision is left stranded.
        return $user->isAdmin() && $version->status === DocumentVersionStatus::PendingValidation;
    }
}
