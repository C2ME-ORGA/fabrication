<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Document;
use App\Models\Job;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use Illuminate\Database\Eloquent\Collection;

class DocumentApplicabilityService
{
    /**
     * Documents applicable to a work order:
     * - documents attached directly to this work order;
     * - documents attached to the affaire without a work order.
     *
     * Documents attached to another work order of the same affaire are not
     * applicable.
     *
     * @return Collection<int, Document>
     */
    public function applicableDocumentsFor(WorkOrder $workOrder): Collection
    {
        return Document::query()
            ->where('status', DocumentStatus::Active)
            ->where(function ($query) use ($workOrder): void {
                $query->where('work_order_id', $workOrder->getKey())
                    ->orWhere(function ($query) use ($workOrder): void {
                        $query->whereNull('work_order_id')
                            ->where('job_id', $workOrder->job_id);
                    });
            })
            ->with(['currentVersion', 'latestApprovedVersion'])
            ->orderBy('category')
            ->orderBy('title')
            ->get();
    }

    /**
     * @return Collection<int, Document>
     */
    public function applicableDocumentsForJob(Job $job): Collection
    {
        return Document::query()
            ->where('status', DocumentStatus::Active)
            ->where('job_id', $job->getKey())
            ->whereNull('work_order_id')
            ->with(['currentVersion', 'latestApprovedVersion'])
            ->orderBy('category')
            ->orderBy('title')
            ->get();
    }

    /**
     * Released work orders whose frozen reference is older than the newly
     * approved version. Completed and cancelled OFs are excluded.
     *
     * @return Collection<int, WorkOrder>
     */
    public function impactedReleasedWorkOrders(Document $document, int $newVersionNumber): Collection
    {
        return WorkOrder::query()
            ->whereIn('status', [
                WorkOrderStatus::Released,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::QualityCheck,
            ])
            ->whereHas('documentSnapshots', function ($query) use ($document, $newVersionNumber): void {
                $query->where('document_id', $document->getKey())
                    ->where('version_number', '<', $newVersionNumber);
            })
            ->with('job')
            ->orderBy('number')
            ->get();
    }

    public function countImpactedReleasedWorkOrders(Document $document, int $newVersionNumber): int
    {
        return $this->impactedReleasedWorkOrders($document, $newVersionNumber)->count();
    }

    /**
     * Current frozen references, annotated with a newer approved version when
     * one exists.
     *
     * @return Collection<int, WorkOrderDocumentSnapshot>
     */
    public function snapshotsWithImpact(WorkOrder $workOrder): Collection
    {
        $snapshots = $workOrder->documentSnapshots()
            ->with(['document.latestApprovedVersion', 'version', 'validator', 'capturedBy'])
            ->get();

        return $snapshots->each(function (WorkOrderDocumentSnapshot $snapshot): void {
            $latestApproved = $snapshot->document?->isArchived()
                ? null
                : $snapshot->document?->latestApprovedVersion;

            $snapshot->setAttribute(
                'newer_approved_version',
                $latestApproved !== null && $latestApproved->version_number > $snapshot->version_number
                    ? $latestApproved
                    : null,
            );
        });
    }

    /**
     * @return Collection<int, WorkOrderDocumentSnapshot>
     */
    public function releaseSnapshotsFor(WorkOrder $workOrder): Collection
    {
        return $workOrder->documentSnapshots()
            ->where('reason', 'release')
            ->with(['document', 'version', 'validator', 'capturedBy'])
            ->orderBy('captured_at')
            ->get();
    }
}
