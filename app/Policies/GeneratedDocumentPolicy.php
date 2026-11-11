<?php

namespace App\Policies;

use App\Models\GeneratedDocument;
use App\Models\User;
use App\Models\WorkOrder;

class GeneratedDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            || $user->isWorkshopSupervisor()
            || $user->isQualityManager();
    }

    public function view(User $user, GeneratedDocument $document): bool
    {
        $workOrder = $document->workOrder;

        if ($workOrder === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isWorkshopSupervisor() && ! $user->isQualityManager()) {
            return false;
        }

        return $workOrder->isAccessibleBy($user);
    }

    public function download(User $user, GeneratedDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function generate(User $user, WorkOrder $workOrder): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, GeneratedDocument $document): bool
    {
        return false;
    }

    public function delete(User $user, GeneratedDocument $document): bool
    {
        return false;
    }
}
