<?php

namespace Tests\Feature\Concerns;

use App\Enums\DocumentSnapshotReason;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;

trait BuildsDocumentReviewScenarios
{
    protected function applicableDocument(WorkOrder $workOrder, string $reference = 'DOC-REV-001'): Document
    {
        return Document::factory()->create([
            'job_id' => $workOrder->job_id,
            'work_order_id' => null,
            'status' => DocumentStatus::Active,
            'reference' => $reference,
            'title' => 'Plan '.$reference,
        ]);
    }

    protected function approvedVersion(
        Document $document,
        string $revision = 'A',
        int $versionNumber = 1,
    ): DocumentVersion {
        return DocumentVersion::factory()->approved()->create([
            'document_id' => $document->getKey(),
            'revision' => $revision,
            'version_number' => $versionNumber,
        ]);
    }

    protected function releaseSnapshot(
        WorkOrder $workOrder,
        Document $document,
        DocumentVersion $version,
        DocumentSnapshotReason $reason = DocumentSnapshotReason::Release,
    ): WorkOrderDocumentSnapshot {
        return WorkOrderDocumentSnapshot::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'document_id' => $document->getKey(),
            'document_version_id' => $version->getKey(),
            'category' => $document->category,
            'title' => $document->title,
            'reference' => $document->reference,
            'revision' => $version->revision,
            'version_number' => $version->version_number,
            'sha256' => $version->sha256,
            'captured_at' => now()->subHour(),
            'reason' => $reason,
        ]);
    }
}
