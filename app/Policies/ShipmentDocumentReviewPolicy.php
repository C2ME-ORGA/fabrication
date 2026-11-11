<?php

namespace App\Policies;

use App\Models\ShipmentDocumentReview;
use App\Models\User;

class ShipmentDocumentReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isWorkshopSupervisor() || $user->isQualityManager();
    }

    public function view(User $user, ShipmentDocumentReview $review): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isWorkshopSupervisor() && ! $user->isQualityManager()) {
            return false;
        }

        return $review->workOrder?->isAccessibleBy($user) === true;
    }
}
