<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Document $document): bool
    {
        return $user->isAdmin() && ! $document->isArchived();
    }

    /**
     * Documents are never destroyed: versions and snapshots are historical.
     * The supported lifecycle end state is archiving.
     */
    public function delete(User $user, Document $document): bool
    {
        return false;
    }

    public function download(User $user, Document $document): bool
    {
        return $user->isAdmin();
    }

    public function uploadVersion(User $user, Document $document): bool
    {
        return $user->isAdmin() && ! $document->isArchived();
    }

    public function archive(User $user, Document $document): bool
    {
        return $user->isAdmin() && ! $document->isArchived();
    }

    public function viewHistory(User $user, Document $document): bool
    {
        return $user->isAdmin();
    }

    public function viewImpactedWorkOrders(User $user, Document $document): bool
    {
        return $user->isAdmin();
    }
}
