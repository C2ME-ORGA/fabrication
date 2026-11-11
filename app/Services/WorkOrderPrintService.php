<?php

namespace App\Services;

use App\Contracts\WorkOrderQualityReleaseSource;
use App\DTO\WorkOrderPrintData;
use App\DTO\WorkOrderPrintItemLine;
use App\DTO\WorkOrderPrintOperationLine;
use App\DTO\WorkOrderPrintQualitySummary;
use App\DTO\WorkOrderPrintVisa;
use App\Enums\DocumentSnapshotReason;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkshopOperationAuditEvent;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAuditLog;
use App\Models\WorkOrderDocumentSnapshot;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Construit les données du PDF d'OF à partir des seules données persistées.
 */
class WorkOrderPrintService
{
    public function __construct(
        protected DocumentApplicabilityService $applicability,
        protected QualityControlEvaluationService $qualityEvaluation,
        protected WorkOrderQualityReleaseSource $qualityReleases,
    ) {}

    public function build(WorkOrder $workOrder, ?User $actor = null): WorkOrderPrintData
    {
        return DB::transaction(function () use ($workOrder, $actor): WorkOrderPrintData {
            $workOrder = WorkOrder::query()
                ->with([
                    'job',
                    'items',
                    'operations.timeEntries.user',
                    'operations.auditLogs.user',
                    'qualityControls.points.results.validations',
                    'nonConformities',
                    'documentSnapshots.version',
                    'documentSnapshots.document.latestApprovedVersion',
                    'documentSnapshots.validator',
                    'auditLogs.user',
                    'creator',
                    'releaser',
                    'responsible',
                    'beApprover',
                ])
                ->findOrFail($workOrder->getKey());

            $applicableDocuments = $this->applicability->applicableDocumentsFor($workOrder);

            // Seuls les instantanés de libération et de remise en revue
            // figent les documents du PDF. Les instantanés d'approbation BE
            // servent au contrôle de cohérence, pas à la révision imprimée.
            $releaseSnapshots = $workOrder->documentSnapshots
                ->filter(fn ($snapshot): bool => in_array($snapshot->reason, [
                    DocumentSnapshotReason::Release,
                    DocumentSnapshotReason::ReReview,
                ], true))
                ->sortBy([
                    ['captured_at', 'desc'],
                    ['id', 'desc'],
                ]);

            $snapshotsByDocument = $releaseSnapshots
                ->unique('document_id')
                ->keyBy('document_id');

            $items = $workOrder->items
                ->sortBy('mark')
                ->map(fn ($item): WorkOrderPrintItemLine => new WorkOrderPrintItemLine(
                    mark: $item->mark,
                    designation: $item->designation,
                    profile: $item->profile,
                    steelGrade: $item->steel_grade,
                    lengthMm: $item->length_mm === null ? null : (string) $item->length_mm,
                    quantityPlanned: (int) $item->quantity_planned,
                    quantityProduced: (int) $item->quantity_produced,
                    quantityRejected: (int) $item->quantity_rejected,
                    quantityAccepted: (int) $item->accepted_quantity,
                    quantityRemaining: (int) $item->remaining_quantity,
                    totalWeightKg: $item->total_weight_kg === null ? null : (string) $item->total_weight_kg,
                    operationGroup: $item->operation_group,
                    statusLabel: $item->status->getLabel(),
                ))
                ->values()
                ->all();

            $operations = $workOrder->operations
                ->map(fn (WorkOrderOperation $operation): WorkOrderPrintOperationLine => new WorkOrderPrintOperationLine(
                    position: (int) $operation->position,
                    code: $operation->code,
                    name: $operation->name,
                    instructions: $operation->instructions,
                    estimatedDurationMinutes: $operation->estimated_duration_minutes,
                    realSeconds: $operation->recordedDurationSeconds(),
                    statusLabel: $operation->status->getLabel(),
                    startedAt: $this->date($operation->started_at),
                    completedAt: $this->date($operation->completed_at),
                    operators: $operation->timeEntries
                        ->pluck('user.name')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ))
                ->values()
                ->all();

            $documents = $applicableDocuments
                ->map(fn (Document $document): array => $this->documentRow($document, $snapshotsByDocument->get($document->getKey())))
                ->values()
                ->all();

            $qualityControls = $workOrder->qualityControls
                ->map(fn (WorkOrderQualityControl $control): WorkOrderPrintQualitySummary => $this->qualityRow($control))
                ->values()
                ->all();

            $nonConformities = $workOrder->nonConformities
                ->map(fn ($nonConformity): array => [
                    'reference' => $nonConformity->reference,
                    'severity' => $nonConformity->severity->getLabel(),
                    'status' => $nonConformity->status->getLabel(),
                    'is_blocking' => (bool) $nonConformity->is_blocking,
                    'detected_at' => $this->date($nonConformity->detected_at),
                ])
                ->values()
                ->all();

            $visas = $this->visas($workOrder);
            $visaSnapshot = collect($visas)
                ->mapWithKeys(fn (WorkOrderPrintVisa $visa): array => [$visa->type => $visa->toArray()])
                ->all();

            $lastCompletedOperation = $this->lastCompletedOperation($workOrder);

            $auditLogs = $workOrder->auditLogs
                ->take(10)
                ->map(fn (WorkOrderAuditLog $log): array => [
                    'event' => WorkOrderAuditEvent::tryFrom($log->event)?->getLabel() ?? $log->event,
                    'user' => $log->user?->name,
                    'date' => $this->date($log->created_at),
                    'comment' => $log->comment,
                ])
                ->values()
                ->all();

            $ofRevision = $this->ofRevision($releaseSnapshots, $documents);

            $revisionSnapshot = $applicableDocuments
                ->map(fn (Document $document): array => [
                    'document_id' => (int) $document->getKey(),
                    'reference' => $document->reference,
                    'title' => $document->title,
                    'revision' => $snapshotsByDocument->get($document->getKey())?->revision
                        ?? $document->latestApprovedVersion?->revision,
                    'version_number' => $snapshotsByDocument->get($document->getKey())?->version_number
                        ?? $document->latestApprovedVersion?->version_number,
                    'sha256' => $snapshotsByDocument->get($document->getKey())?->sha256
                        ?? $document->latestApprovedVersion?->sha256,
                    'source' => $snapshotsByDocument->has($document->getKey()) ? 'frozen' : 'current',
                ])
                ->values()
                ->all();

            $printedAt = now()->toIso8601String();
            $printedBy = $actor?->name ?? Auth::user()?->name;
            $templateVersion = (string) config('pdf.template_version', '1');

            $payload = [
                'job' => [
                    'code' => $workOrder->job?->code,
                    'client_name' => $workOrder->job?->client_name,
                    'name' => $workOrder->job?->name,
                    'site_address' => $workOrder->job?->site_address,
                ],
                'work_order' => [
                    'number' => $workOrder->number,
                    'title' => $workOrder->title,
                    'description' => $workOrder->description,
                    'status' => $workOrder->status->value,
                    'priority' => $workOrder->priority->value,
                    'planned_start_at' => $this->date($workOrder->planned_start_at),
                    'planned_end_at' => $this->date($workOrder->planned_end_at),
                    'actual_start_at' => $this->date($workOrder->actual_start_at),
                    'actual_end_at' => $this->date($workOrder->actual_end_at),
                    'responsible' => $workOrder->responsible?->name,
                ],
                'of_revision' => $ofRevision,
                'items' => array_map(fn (WorkOrderPrintItemLine $item): array => $item->toArray(), $items),
                'operations' => array_map(fn (WorkOrderPrintOperationLine $operation): array => $operation->toArray(), $operations),
                'documents' => $documents,
                'quality_controls' => array_map(fn (WorkOrderPrintQualitySummary $quality): array => $quality->toArray(), $qualityControls),
                'non_conformities' => $nonConformities,
                'visas' => $visaSnapshot,
                'last_completed_operation' => $lastCompletedOperation,
                'audit_logs' => $auditLogs,
                'template_version' => $templateVersion,
            ];

            $dataHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return new WorkOrderPrintData(
                workOrder: $workOrder,
                job: $workOrder->job,
                ofRevision: $ofRevision,
                items: $items,
                operations: $operations,
                documents: $documents,
                qualityControls: $qualityControls,
                nonConformities: $nonConformities,
                visas: $visas,
                auditLogs: $auditLogs,
                lastCompletedOperation: $lastCompletedOperation,
                revisionSnapshot: $revisionSnapshot,
                visaSnapshot: $visaSnapshot,
                printedAt: $printedAt,
                printedBy: $printedBy,
                dataHash: $dataHash,
                templateVersion: $templateVersion,
            );
        });
    }

    /**
     * @param  Collection<int, WorkOrderDocumentSnapshot>  $releaseSnapshots
     * @param  array<int, array<string, mixed>>  $documents
     */
    protected function ofRevision(Collection $releaseSnapshots, array $documents): string
    {
        if ($releaseSnapshots->isNotEmpty()) {
            $revisions = $releaseSnapshots
                ->pluck('revision')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $revisions === [] ? 'Non figée' : implode(' / ', $revisions);
        }

        return $documents === [] ? 'Aucun document applicable' : 'Non figée';
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentRow(Document $document, $snapshot): array
    {
        $current = $document->latestApprovedVersion;
        $revision = $snapshot?->revision ?? $current?->revision;
        $versionNumber = $snapshot?->version_number ?? $current?->version_number;
        $validatedAt = $snapshot?->validated_at ?? $current?->validated_at;

        return [
            'document_id' => (int) $document->getKey(),
            'title' => $document->title,
            'reference' => $document->reference,
            'category' => $document->category->getLabel(),
            'revision' => $revision,
            'version_number' => $versionNumber,
            'validated_at' => $this->date($validatedAt),
            'is_frozen' => $snapshot !== null,
            'current_revision' => $current?->revision,
            'has_newer_approved' => $snapshot !== null
                && $snapshot->version_number !== null
                && $current !== null
                && $current->version_number > $snapshot->version_number,
        ];
    }

    protected function qualityRow(WorkOrderQualityControl $control): WorkOrderPrintQualitySummary
    {
        $progress = $this->qualityEvaluation->progress($control);

        return new WorkOrderPrintQualitySummary(
            templateCode: $control->template_code,
            templateName: $control->template_name,
            templateVersion: (int) $control->template_version_number,
            controlTypeLabel: $control->control_type->getLabel(),
            scopeLabel: $control->scope->getLabel(),
            isMandatory: (bool) $control->is_mandatory,
            statusLabel: $control->status->getLabel(),
            requiredResults: (int) $progress->sum(fn ($item): int => $item->requiredResults),
            conformingResults: (int) $progress->sum(fn ($item): int => $item->conformingResults),
        );
    }

    /**
     * @return array<int, WorkOrderPrintVisa>
     */
    protected function visas(WorkOrder $workOrder): array
    {
        $qualityRelease = $this->qualityReleases->latestFor($workOrder);

        // Les colonnes persistées sont la seule preuve de l'état actuel. Un
        // événement d'audit historique ne doit jamais rétablir une approbation
        // ou une libération invalidée : il reste disponible dans l'historique,
        // mais n'est plus utilisé comme preuve.
        $beProof = $workOrder->be_approved_at !== null && $workOrder->be_approved_by !== null;
        $releaseProof = $workOrder->released_at !== null && $workOrder->released_by !== null;

        $bePerson = $beProof ? $workOrder->beApprover : null;
        $beDate = $beProof ? $this->date($workOrder->be_approved_at) : null;

        $releasePerson = $releaseProof ? $workOrder->releaser : null;
        $releaseDate = $releaseProof ? $this->date($workOrder->released_at) : null;

        $qualityPerson = $qualityRelease?->releasedBy;
        $qualityDate = $qualityRelease?->releasedAt;
        $qualityProof = $qualityRelease !== null && $qualityRelease->eligible;

        return [
            $this->makeVisa(
                WorkOrderPrintVisa::TYPE_BUREAU_ETUDES,
                $beProof,
                $bePerson,
                $beDate,
                WorkOrderPrintVisa::EVIDENCE_BE,
                null,
                'work_orders.be_approved_at',
            ),
            $this->makeVisa(
                WorkOrderPrintVisa::TYPE_LIBERATION,
                $releaseProof,
                $releasePerson,
                $releaseDate,
                WorkOrderPrintVisa::EVIDENCE_RELEASE,
                null,
                'work_orders.released_at',
            ),
            $this->makeVisa(
                WorkOrderPrintVisa::TYPE_QUALITE,
                $qualityProof,
                $qualityPerson,
                $qualityDate,
                WorkOrderPrintVisa::EVIDENCE_QUALITY,
                $qualityRelease?->id,
                $qualityRelease?->reference,
            ),
            new WorkOrderPrintVisa(
                WorkOrderPrintVisa::TYPE_ATELIER,
                WorkOrderPrintVisa::STATUS_NON_EFFECTUE,
                null,
                null,
                null,
                WorkOrderPrintVisa::EVIDENCE_NONE,
                null,
                null,
            ),
        ];
    }

    protected function makeVisa(
        string $type,
        bool $proofExists,
        ?User $person,
        ?string $date,
        string $evidenceType,
        ?int $evidenceId,
        ?string $evidenceReference,
    ): WorkOrderPrintVisa {
        if (! $proofExists) {
            return new WorkOrderPrintVisa(
                $type,
                WorkOrderPrintVisa::STATUS_NON_EFFECTUE,
                null,
                null,
                null,
                WorkOrderPrintVisa::EVIDENCE_NONE,
                null,
                null,
            );
        }

        $status = $person !== null && $date !== null
            ? WorkOrderPrintVisa::STATUS_EFFECTUE
            : WorkOrderPrintVisa::STATUS_NON_RENSEIGNE;

        return new WorkOrderPrintVisa(
            $type,
            $status,
            $person?->name,
            $this->functionLabel($person),
            $date,
            $evidenceType,
            $evidenceId,
            $evidenceReference,
        );
    }

    protected function functionLabel(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $labels = [];

        if ($user->isAdmin()) {
            $labels[] = 'Administrateur';
        }

        if ($user->isQualityManager()) {
            $labels[] = 'Responsable qualité';
        }

        if ($user->isWorkshopSupervisor()) {
            $labels[] = 'Responsable atelier';
        }

        return $labels === [] ? 'Opérateur' : implode(' / ', $labels);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function lastCompletedOperation(WorkOrder $workOrder): ?array
    {
        $operation = $workOrder->operations
            ->filter(fn (WorkOrderOperation $operation): bool => $operation->completed_at !== null)
            ->sortByDesc('completed_at')
            ->first();

        if ($operation === null) {
            return null;
        }

        $audit = $operation->auditLogs->first(
            fn ($log): bool => $log->event === WorkshopOperationAuditEvent::Completed,
        );

        return [
            'code' => $operation->code,
            'name' => $operation->name,
            'completed_at' => $this->date($operation->completed_at),
            'operator' => $audit?->user?->name,
            'audit_id' => $audit?->getKey(),
        ];
    }

    protected function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return (string) $value;
    }
}
