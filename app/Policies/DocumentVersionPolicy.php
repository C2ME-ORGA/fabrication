<?php

namespace App\Policies;

use App\Enums\DocumentVersionStatus;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WorkOrder;

class DocumentVersionPolicy
{
    public function view(User $user, DocumentVersion $version): bool
    {
        return $this->canRead($user, $version);
    }

    public function download(User $user, DocumentVersion $version): bool
    {
        // Archived documents and superseded revisions remain readable for
        // history, but can no longer receive versions.
        return $this->canRead($user, $version);
    }

    protected function canRead(User $user, DocumentVersion $version): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // P7 : le responsable qualité doit pouvoir examiner les documents
        // applicables à un OF qu'il est habilité à consulter. Les autres
        // profils conservent les règles existantes.
        if (! $user->isQualityManager()) {
            return false;
        }

        $document = $version->document;

        if ($document === null) {
            return false;
        }

        if ($document->work_order_id !== null) {
            $workOrder = WorkOrder::query()->find($document->work_order_id);

            return $workOrder !== null && $workOrder->isAccessibleBy($user);
        }

        return WorkOrder::query()
            ->where('job_id', $document->job_id)
            ->get()
            ->contains(fn (WorkOrder $workOrder): bool => $workOrder->isAccessibleBy($user));
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
