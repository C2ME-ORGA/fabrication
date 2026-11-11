<?php

namespace App\Services;

use App\DTO\ShipmentDocumentReviewRequirement;
use App\Enums\DocumentSnapshotReason;
use App\Enums\ShipmentAuditEvent;
use App\Enums\ShipmentDocumentReviewDecision;
use App\Enums\WorkOrderAuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Shipment;
use App\Models\ShipmentDocumentReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Workflow documentaire P7.
 *
 * Une décision est portée par un OF, un document et une version précise.
 * L'expédition est conservée comme contexte lorsqu'elle existe, mais la
 * décision vaut pour l'OF : une nouvelle version repart systématiquement en
 * attente de décision.
 *
 * Ordre de verrouillage pour les contrôles : OF → Job → Documents (ID
 * croissant) → versions (via le verrou du Document). Cet ordre est cohérent
 * avec DocumentService et DocumentVersionService, qui verrouillent les
 * parents avant le document puis la version.
 */
class ShipmentDocumentService
{
    public function __construct(
        protected DocumentApplicabilityService $applicability,
        protected WorkOrderAuditService $workOrderAudit,
        protected ShipmentAuditService $shipmentAudit,
        protected DocumentLockingService $locking,
    ) {}

    /**
     * Révisions applicables qui nécessitent une décision P7.
     *
     * @return Collection<int, ShipmentDocumentReviewRequirement>
     */
    public function requirements(WorkOrder $workOrder): Collection
    {
        $applicableDocuments = $this->applicability->applicableDocumentsFor($workOrder);

        // Le dernier snapshot de libération ou de remise en revue constitue
        // la référence de comparaison. Son absence n'est jamais ignorée.
        $baselines = $workOrder->documentSnapshots()
            ->reorder()
            ->whereIn('reason', [
                DocumentSnapshotReason::Release->value,
                DocumentSnapshotReason::ReReview->value,
            ])
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get()
            ->keyBy('document_id');

        $requirements = new Collection;

        foreach ($applicableDocuments as $document) {
            $version = $document->latestApprovedVersion;

            if ($version === null) {
                continue;
            }

            $baseline = $baselines->get($document->getKey());

            if ($baseline !== null && $this->isSameVersion($baseline, $version)) {
                continue;
            }

            $requirements->push(new ShipmentDocumentReviewRequirement(
                $document,
                $version,
                $baseline,
                $this->latestDecision($workOrder, $document, $version),
            ));
        }

        return $requirements;
    }

    public function assertDocumentSituationDecided(
        WorkOrder $workOrder,
        ?Shipment $shipment = null,
    ): void {
        // Verrouiller les documents applicables avant de relire leurs
        // versions. L'approbation documentaire verrouille le même Document et
        // ne peut donc plus modifier une version entre le contrôle et le
        // commit de la validation ou du départ.
        $this->lockApplicableDocuments($workOrder);

        $requirements = $this->requirements($workOrder);

        $blocked = $requirements->filter(fn (ShipmentDocumentReviewRequirement $requirement): bool => $requirement->isBlocked());

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => 'Une décision documentaire bloquante interdit la poursuite : '.$this->references($blocked).'.',
            ]);
        }

        $pending = $requirements->filter(fn (ShipmentDocumentReviewRequirement $requirement): bool => $requirement->isPending());

        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => 'Une révision documentaire doit être examinée avant de poursuivre : '.$this->references($pending).'.',
            ]);
        }
    }

    public function decide(
        WorkOrder $workOrder,
        Document $document,
        DocumentVersion $version,
        ShipmentDocumentReviewDecision $decision,
        User $actor,
        string $comment,
        ?Shipment $shipment = null,
    ): ShipmentDocumentReview {
        if (! $actor->exists) {
            throw ValidationException::withMessages([
                'user' => 'Un responsable qualité enregistré est requis.',
            ]);
        }

        Gate::forUser($actor)->authorize('decideDocumentReview', $workOrder);

        $comment = trim($comment);

        if (mb_strlen($comment) < 3) {
            throw ValidationException::withMessages([
                'comment' => 'Un commentaire d’au moins 3 caractères est obligatoire.',
            ]);
        }

        if (mb_strlen($comment) > 10000) {
            throw ValidationException::withMessages([
                'comment' => 'Le commentaire ne peut pas dépasser 10 000 caractères.',
            ]);
        }

        return DB::transaction(function () use ($workOrder, $document, $version, $decision, $actor, $comment, $shipment): ShipmentDocumentReview {
            // Même ordre que l'approbation documentaire : parents
            // (OF/Job) triés par ID → Document → Version. Une approbation
            // concurrente ne peut donc pas changer la version entre la
            // vérification et la décision.
            $lockedDocument = $this->locking->lockDocument(
                $document->getKey(),
                additionalWorkOrderIds: [$workOrder->getKey()],
                additionalJobIds: [$workOrder->job_id],
            );

            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $lockedVersion = DocumentVersion::query()->lockForUpdate()->findOrFail($version->getKey());

            $requirement = $this->requirements($locked)->first(
                fn (ShipmentDocumentReviewRequirement $item): bool => (int) $item->document->getKey() === (int) $lockedDocument->getKey()
                    && (int) $item->version->getKey() === (int) $lockedVersion->getKey(),
            );

            if ($requirement === null) {
                throw ValidationException::withMessages([
                    'document' => 'Cette version ne constitue pas une nouvelle révision à examiner pour cet OF.',
                ]);
            }

            if ($shipment !== null && (int) $shipment->work_order_id !== (int) $locked->getKey()) {
                throw ValidationException::withMessages([
                    'shipment' => 'L’expédition n’appartient pas à cet OF.',
                ]);
            }

            $review = ShipmentDocumentReview::query()->create([
                'work_order_id' => $locked->getKey(),
                'shipment_id' => $shipment?->getKey(),
                'document_id' => $lockedDocument->getKey(),
                'document_version_id' => $lockedVersion->getKey(),
                'decision' => $decision,
                'comment' => $comment,
                'revision_snapshot' => [
                    'baseline' => $this->snapshotData($requirement->baseline),
                    'current' => [
                        'document_version_id' => (int) $lockedVersion->getKey(),
                        'revision' => $lockedVersion->revision,
                        'version_number' => (int) $lockedVersion->version_number,
                        'sha256' => $lockedVersion->sha256,
                        'validated_at' => $lockedVersion->validated_at?->toIso8601String(),
                    ],
                ],
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'created_at' => now(),
            ]);

            $metadata = [
                'review_id' => (int) $review->getKey(),
                'document_id' => (int) $lockedDocument->getKey(),
                'document_version_id' => (int) $lockedVersion->getKey(),
                'decision' => $decision->value,
                'revision' => $lockedVersion->revision,
                'version_number' => (int) $lockedVersion->version_number,
            ];

            $this->workOrderAudit->record(
                $locked,
                WorkOrderAuditEvent::DocumentReviewDecided,
                $actor,
                null,
                $decision->value,
                $comment,
                $metadata,
            );

            if ($shipment !== null) {
                $this->shipmentAudit->record(
                    $shipment,
                    ShipmentAuditEvent::DocumentReviewDecided,
                    $actor,
                    null,
                    $decision->value,
                    $comment,
                    $metadata,
                );
            }

            return $review->refresh();
        }, attempts: 3);
    }

    public function latestDecision(
        WorkOrder $workOrder,
        Document $document,
        DocumentVersion $version,
    ): ?ShipmentDocumentReview {
        return ShipmentDocumentReview::query()
            ->where('work_order_id', $workOrder->getKey())
            ->where('document_id', $document->getKey())
            ->where('document_version_id', $version->getKey())
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Verrouille les documents applicables dans l'ordre global
     * OF → Job → Document (ID croissant).
     *
     * DocumentVersionService::approve() verrouille le même Document avant de
     * modifier la version. Tant que la transaction de validation ou de départ
     * conserve ce verrou, aucune nouvelle version ne peut donc être approuvée
     * entre le contrôle P7 et le commit.
     */
    protected function lockApplicableDocuments(WorkOrder $workOrder): void
    {
        $documentIds = $this->applicability
            ->applicableDocumentsFor($workOrder)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($documentIds->isEmpty()) {
            return;
        }

        foreach ($documentIds as $documentId) {
            // Les parents (OF, Job) sont fusionnés et verrouillés par ID
            // croissant avant chaque Document. La première itération acquiert
            // l'OF et le Job ; les suivantes les retrouvent déjà verrouillés.
            $this->locking->lockDocument(
                $documentId,
                additionalWorkOrderIds: [$workOrder->getKey()],
                additionalJobIds: [$workOrder->job_id],
            );
        }
    }

    protected function isSameVersion(
        WorkOrderDocumentSnapshot $baseline,
        DocumentVersion $version,
    ): bool {
        return (int) $baseline->document_version_id === (int) $version->getKey()
            && (string) $baseline->revision === (string) $version->revision
            && (int) $baseline->version_number === (int) $version->version_number
            && (string) $baseline->sha256 === (string) $version->sha256;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function snapshotData(?WorkOrderDocumentSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'snapshot_id' => (int) $snapshot->getKey(),
            'document_version_id' => $snapshot->document_version_id === null ? null : (int) $snapshot->document_version_id,
            'revision' => $snapshot->revision,
            'version_number' => $snapshot->version_number === null ? null : (int) $snapshot->version_number,
            'sha256' => $snapshot->sha256,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, ShipmentDocumentReviewRequirement>  $requirements
     */
    protected function references(Collection $requirements): string
    {
        return $requirements
            ->map(fn (ShipmentDocumentReviewRequirement $requirement): string => $requirement->reference()
                .' v'.($requirement->version->version_number ?? '?')
                .' ('.($requirement->version->revision ?? '?').')')
            ->unique()
            ->implode(', ');
    }
}
