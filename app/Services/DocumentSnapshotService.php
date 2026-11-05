<?php

namespace App\Services;

use App\Enums\DocumentAuditAction;
use App\Enums\DocumentSnapshotReason;
use App\Enums\WorkOrderStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentSnapshotService
{
    public function __construct(
        protected DocumentApplicabilityService $applicability,
        protected DocumentAuditService $audit,
    ) {}

    /**
     * Capture the approved versions applicable at release time. Called inside
     * WorkOrderService's release transaction. Existing release snapshots are
     * left untouched.
     *
     * @return Collection<int, WorkOrderDocumentSnapshot>
     */
    public function captureForRelease(WorkOrder $workOrder, ?User $actor = null): Collection
    {
        $created = new Collection;

        foreach ($this->applicability->applicableDocumentsFor($workOrder) as $document) {
            $version = $document->latestApprovedVersion;

            if ($version === null) {
                continue;
            }

            $exists = WorkOrderDocumentSnapshot::query()
                ->where('work_order_id', $workOrder->getKey())
                ->where('document_id', $document->getKey())
                ->where('reason', DocumentSnapshotReason::Release->value)
                ->exists();

            if ($exists) {
                continue;
            }

            $snapshot = $this->createSnapshot($workOrder, $document, $version, DocumentSnapshotReason::Release, $actor);
            $created->push($snapshot);
        }

        return $created;
    }

    /**
     * Capture the approved document versions applicable at BE approval time.
     * Creates one snapshot per applicable document, tagged with a shared
     * batch UUID so all snapshots of the same BE approval can be identified
     * and compared at release time.
     *
     * Documents without an approved version are still captured (with a null
     * document_version_id) so that the snapshot reflects the real state,
     * including missing approvals.
     *
     * @return array{Collection<int, WorkOrderDocumentSnapshot>, string} The created snapshots and the batch UUID.
     */
    public function captureForBEApproval(WorkOrder $workOrder, ?User $actor = null): array
    {
        $created = new Collection;
        $batchId = (string) Str::uuid();

        foreach ($this->applicability->applicableDocumentsFor($workOrder) as $document) {
            $version = $document->latestApprovedVersion;

            $snapshot = $this->createSnapshot(
                $workOrder,
                $document,
                $version,
                DocumentSnapshotReason::BeApproval,
                $actor,
                null,
                $batchId,
            );
            $created->push($snapshot);
        }

        return [$created, $batchId];
    }

    /**
     * Compares the current applicable document state with the BE approval
     * snapshots captured in the given batch. Returns true if the state is
     * strictly identical (same documents, same approved versions, same
     * revisions, same SHA-256), false otherwise.
     *
     * Documents that had no approved version at approval time but now do are
     * considered a change.
     */
    public function isBEApprovalStillValid(WorkOrder $workOrder, string $batchId): bool
    {
        $approvedSnapshots = $workOrder->documentSnapshots()
            ->where('reason', DocumentSnapshotReason::BeApproval->value)
            ->where('be_approval_batch_id', $batchId)
            ->get();

        $applicableDocuments = $this->applicability->applicableDocumentsFor($workOrder);

        // Same number of applicable documents.
        if ($approvedSnapshots->count() !== $applicableDocuments->count()) {
            return false;
        }

        // Build a lookup of approved snapshots by document ID.
        $snapshotByDocument = $approvedSnapshots->keyBy('document_id');

        foreach ($applicableDocuments as $document) {
            $snapshot = $snapshotByDocument->get($document->getKey());

            // Document was applicable at approval time but is no longer applicable,
            // or a new document became applicable after approval.
            if ($snapshot === null) {
                return false;
            }

            $currentVersion = $document->latestApprovedVersion;

            // Document had no approved version at approval time but now does,
            // or vice versa.
            if (($snapshot->document_version_id === null) !== ($currentVersion === null)) {
                return false;
            }

            // Both have a version: compare version ID, revision, SHA-256 and
            // document metadata (category, title, reference).
            if ($currentVersion !== null) {
                if ($snapshot->document_version_id !== $currentVersion->getKey()) {
                    return false;
                }
                if ($snapshot->revision !== $currentVersion->revision) {
                    return false;
                }
                if ($snapshot->sha256 !== $currentVersion->sha256) {
                    return false;
                }
                // Document metadata may have been edited after BE approval.
                if ($snapshot->category !== $document->category) {
                    return false;
                }
                if ($snapshot->title !== $document->title) {
                    return false;
                }
                if ($snapshot->reference !== $document->reference) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Explicit re-review after a new revision. Historical release snapshots
     * are preserved; new rows are appended with the re-review reason.
     *
     * @return Collection<int, WorkOrderDocumentSnapshot>
     */
    public function refreshForWorkOrder(WorkOrder $workOrder, User $actor, string $comment): Collection
    {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Un motif est obligatoire pour remettre les documents en revue.',
            ]);
        }

        if (! in_array($workOrder->status, [
            WorkOrderStatus::Released,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::QualityCheck,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => "La remise en revue documentaire n'est possible que pour un OF libéré, en cours ou en contrôle qualité.",
            ]);
        }

        return DB::transaction(function () use ($workOrder, $actor, $comment): Collection {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());

            // Re-check under the row lock: the OF may have been completed or
            // cancelled while the action was being prepared.
            if (! in_array($locked->status, [
                WorkOrderStatus::Released,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::QualityCheck,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => "La remise en revue documentaire n'est plus possible pour cet OF.",
                ]);
            }

            $created = new Collection;

            foreach ($this->applicability->applicableDocumentsFor($locked) as $document) {
                $version = $document->latestApprovedVersion;

                if ($version === null) {
                    continue;
                }

                $created->push($this->createSnapshot(
                    $locked,
                    $document,
                    $version,
                    DocumentSnapshotReason::ReReview,
                    $actor,
                    $comment,
                ));
            }

            $this->audit->log(
                DocumentAuditAction::DocumentsReReviewed,
                workOrder: $locked,
                actor: $actor,
                comment: $comment,
                metadata: [
                    'snapshot_count' => $created->count(),
                    'document_ids' => $created->pluck('document_id')->unique()->values()->all(),
                ],
            );

            return $created;
        }, attempts: 3);
    }

    protected function createSnapshot(
        WorkOrder $workOrder,
        Document $document,
        ?DocumentVersion $version,
        DocumentSnapshotReason $reason,
        ?User $actor,
        ?string $comment = null,
        ?string $beApprovalBatchId = null,
    ): WorkOrderDocumentSnapshot {
        $snapshot = WorkOrderDocumentSnapshot::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'document_id' => $document->getKey(),
            'document_version_id' => $version?->getKey(),
            'category' => $document->category,
            'title' => $document->title,
            'reference' => $document->reference,
            'revision' => $version?->revision,
            'version_number' => $version?->version_number,
            'sha256' => $version?->sha256,
            'validated_by' => $version?->validated_by,
            'validated_at' => $version?->validated_at,
            'captured_by' => $actor?->getKey(),
            'captured_at' => now(),
            'reason' => $reason,
            'comment' => $comment,
            'be_approval_batch_id' => $beApprovalBatchId,
        ]);

        $this->audit->log(
            DocumentAuditAction::SnapshotCaptured,
            $document,
            $version,
            $workOrder,
            $actor,
            $comment,
            [
                'reason' => $reason->value,
                'snapshot_id' => $snapshot->getKey(),
                'revision' => $version?->revision,
                'version_number' => $version?->version_number,
            ],
        );

        return $snapshot;
    }
}
