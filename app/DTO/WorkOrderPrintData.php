<?php

namespace App\DTO;

use App\Models\Job;
use App\Models\WorkOrder;

/**
 * Données complètes, figées en mémoire, du document OF imprimé.
 */
final readonly class WorkOrderPrintData
{
    /**
     * @param  array<int, WorkOrderPrintItemLine>  $items
     * @param  array<int, WorkOrderPrintOperationLine>  $operations
     * @param  array<int, array<string, mixed>>  $documents
     * @param  array<int, WorkOrderPrintQualitySummary>  $qualityControls
     * @param  array<int, array<string, mixed>>  $nonConformities
     * @param  array<int, WorkOrderPrintVisa>  $visas
     * @param  array<int, array<string, mixed>>  $auditLogs
     * @param  array<string, mixed>|null  $lastCompletedOperation
     * @param  array<int, array<string, mixed>>  $revisionSnapshot
     * @param  array<string, array<string, mixed>>  $visaSnapshot
     */
    public function __construct(
        public WorkOrder $workOrder,
        public Job $job,
        public string $ofRevision,
        public array $items,
        public array $operations,
        public array $documents,
        public array $qualityControls,
        public array $nonConformities,
        public array $visas,
        public array $auditLogs,
        public ?array $lastCompletedOperation,
        public array $revisionSnapshot,
        public array $visaSnapshot,
        public string $printedAt,
        public ?string $printedBy,
        public string $dataHash,
        public string $templateVersion,
    ) {}
}
