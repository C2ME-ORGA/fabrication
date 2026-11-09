<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrderBomImport;

class WorkOrderBomImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, WorkOrderBomImport $import): bool
    {
        return $user->isAdmin();
    }

    public function download(User $user, WorkOrderBomImport $import): bool
    {
        return $user->isAdmin();
    }
}
