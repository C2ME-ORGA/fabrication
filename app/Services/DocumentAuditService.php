<?php

namespace App\Services;

use App\Enums\DocumentAuditAction;
use App\Models\Document;
use App\Models\DocumentAuditLog;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;

class DocumentAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        DocumentAuditAction $action,
        ?Document $document = null,
        ?DocumentVersion $version = null,
        ?WorkOrder $workOrder = null,
        ?User $actor = null,
        ?string $comment = null,
        array $metadata = [],
    ): DocumentAuditLog {
        return DocumentAuditLog::query()->create([
            'document_id' => $document?->getKey() ?? $version?->document_id,
            'document_version_id' => $version?->getKey(),
            'work_order_id' => $workOrder?->getKey(),
            'action' => $action->value,
            'user_id' => $actor?->getKey() ?? Auth::id(),
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
