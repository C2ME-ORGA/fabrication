<?php

namespace App\\Services;

use App\\Models\\Document;
use App\\Models\\Job;
use App\\Models\\WorkOrder;
use Illuminate\\Validation\\ValidationException;

class DocumentLockingService
{
    /**
     * Lock parent rows in the global order: WorkOrder(s), then Job(s).
     *
     * A document attached only to a WorkOrder still locks that WorkOrder's Job,
     * because the Job is the serialization point used by OF approval/release.
     *
     * @param  array<int, int|string|null>  $additionalWorkOrderIds
     * @param  array<int, int|string|null>  $additionalJobIds
     */
    public function lockParents(
        int|string|null $workOrderId = null,
        int|string|null $jobId = null,
        array $additionalWorkOrderIds = [],
        array $additionalJobIds = [],
    ): void {
        $workOrderIds = $this->ids([$workOrderId, ...$additionalWorkOrderIds]);
        $jobIds = $this->ids([$jobId, ...$additionalJobIds]);

        $lockedWorkOrders = [];

        foreach ($workOrderIds as $id) {
            $lockedWorkOrders[] = WorkOrder::query()->lockForUpdate()->findOrFail($id);
        }

        foreach ($lockedWorkOrders as $workOrder) {
            $jobIds[] = (int) $workOrder->job_id;
        }

        foreach ($this->ids($jobIds) as $id) {
            Job::query()->lockForUpdate()->findOrFail($id);
        }
    }

    /**
     * Lock current and proposed parents before locking the document.
     *
     * The initial read only discovers parent identifiers. After acquiring the
     * parent locks, the document is locked and its association is rechecked.
     * If another transaction changed the association while this transaction
     * waited, the caller must retry the operation.
     *
     * @param  array<int, int|string|null>  $additionalWorkOrderIds
     * @param  array<int, int|string|null>  $additionalJobIds
     */
    public function lockDocument(
        int|string $documentId,
        array $additionalWorkOrderIds = [],
        array $additionalJobIds = [],
    ): Document {
        $initial = Document::query()->findOrFail($documentId);

        $initialWorkOrderId = $initial->work_order_id;
        $initialJobId = $initial->job_id;

        $this->lockParents(
            $initialWorkOrderId,
            $initialJobId,
            $additionalWorkOrderIds,
            $additionalJobIds,
        );

        $locked = Document::query()->lockForUpdate()->findOrFail($documentId);

        if (
            (int) ($locked->work_order_id ?? 0) !== (int) ($initialWorkOrderId ?? 0)
            || (int) ($locked->job_id ?? 0) !== (int) ($initialJobId ?? 0)
        ) {
            throw ValidationException::withMessages([
                'document' => 'Le rattachement du document a été modifié simultanément. Veuillez réessayer.',
            ]);
        }

        return $locked;
    }

    /**
     * @param  array<int, int|string|null>  $values
     * @return array<int, int>
     */
    private function ids(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($value): int => (int) $value, $values),
            static fn (int $value): bool => $value > 0,
        )));

        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
