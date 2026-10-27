<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\User;

class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Job $job): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Job $job): bool
    {
        return $user->isAdmin();
    }

    /**
     * An affaire holding work orders is never deleted: history is preserved
     * at policy level and by the database RESTRICT foreign key.
     */
    public function delete(User $user, Job $job): bool
    {
        return $user->isAdmin()
            && $job->workOrders()->doesntExist()
            && $job->documents()->doesntExist();
    }
}
