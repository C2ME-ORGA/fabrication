<?php

namespace App\Services;

use App\Enums\GeneratedDocumentType;
use App\Enums\ShipmentAuditEvent;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderItemStatus;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validation définitive d'une expédition préparée.
 *
 * La validation est atomique : contrôles qualité, documents, colisage et
 * cumuls, génération et archivage du BL et de la liste de colisage, puis
 * passage à `validated`. Les fichiers écrits sont supprimés si la transaction
 * ou une étape ultérieure échoue.
 */
class ShipmentValidationService
{
    public function __construct(
        protected ShipmentQualityGateService $qualityGate,
        protected ShipmentDocumentService $documents,
        protected ShipmentPackagingService $packaging,
        protected ShipmentQuantityGuard $quantities,
        protected ShipmentPrintService $print,
        protected GeneratedDocumentStorageService $storage,
        protected GeneratedDocumentArchiveService $archive,
        protected ShipmentAuditService $audit,
        protected ShipmentValidationRefusalRecorder $refusals,
    ) {}

    public function validate(Shipment $shipment, ?User $actor = null): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('validate', $shipment);

        $storedPaths = [];
        $attemptId = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($shipment, $actor, &$storedPaths): Shipment {
                // Ordre de verrouillage FC-8 : OF → pièces → expédition →
                // lignes/colis, puis Job → Documents applicables (ID
                // croissant) au moment du contrôle P7. Les documents restent
                // verrouillés jusqu'au commit ; DocumentVersionService::approve
                // verrouille le même Document avant de modifier une version.
                $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($shipment->work_order_id);

                $itemIds = ShipmentLine::query()
                    ->where('shipment_id', $shipment->getKey())
                    ->orderBy('work_order_item_id')
                    ->pluck('work_order_item_id')
                    ->unique()
                    ->sort()
                    ->values();

                $items = WorkOrderItem::query()
                    ->whereIn('id', $itemIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());
                $locked->setRelation('workOrder', $workOrder);

                if ($locked->status === ShipmentStatus::Validated) {
                    throw ValidationException::withMessages([
                        'status' => 'Cette expédition est déjà validée.',
                    ]);
                }

                if ($locked->status !== ShipmentStatus::Prepared) {
                    throw ValidationException::withMessages([
                        'status' => 'Seule une expédition préparée peut être validée.',
                    ]);
                }

                $this->qualityGate->assertShippable($workOrder);

                $lines = ShipmentLine::query()
                    ->where('shipment_id', $locked->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $packages = ShipmentPackage::query()
                    ->where('shipment_id', $locked->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $locked->setRelation('lines', $lines);
                $locked->setRelation('packages', $packages);

                if ($lines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'preparation' => 'Une expédition sans ligne ne peut pas être validée.',
                    ]);
                }

                if ($packages->isEmpty()) {
                    throw ValidationException::withMessages([
                        'preparation' => 'Une expédition sans colis ne peut pas être validée.',
                    ]);
                }

                $this->packaging->assertCompleteForValidation($locked);

                foreach ($lines as $line) {
                    $item = $items->get($line->work_order_item_id);

                    if ($item === null) {
                        throw ValidationException::withMessages([
                            'quantities' => "La pièce de la ligne {$line->mark_snapshot} est introuvable.",
                        ]);
                    }

                    if ($item->status === WorkOrderItemStatus::Cancelled) {
                        throw ValidationException::withMessages([
                            'quantities' => "La pièce {$line->mark_snapshot} est annulée et ne peut pas être expédiée.",
                        ]);
                    }

                    if ($line->quantity_prepared < 1 || $line->quantity_shipped !== 0) {
                        throw ValidationException::withMessages([
                            'quantities' => "Les quantités de la ligne {$line->mark_snapshot} sont incohérentes.",
                        ]);
                    }

                    $accepted = $this->quantities->acceptedQuantity($item);
                    $reserved = $this->quantities->validatedForItem($item)
                        + $this->quantities->shippedForItem($item);

                    if ($reserved + $line->quantity_prepared > $accepted) {
                        throw ValidationException::withMessages([
                            'quantities' => "La quantité validée pour le repère {$line->mark_snapshot} dépasserait la quantité acceptée ({$accepted}).",
                        ]);
                    }
                }

                $this->documents->assertDocumentSituationDecided($workOrder, $locked);

                if ($locked->delivery_note_reference !== null && trim($locked->delivery_note_reference) === '') {
                    $locked->delivery_note_reference = null;
                }

                if ($locked->delivery_note_reference === null) {
                    $locked->delivery_note_reference = $locked->number.'-BL';
                }

                $this->assertDeliveryNoteReferenceAvailable(
                    $locked->work_order_id,
                    $locked->delivery_note_reference,
                    $locked->getKey(),
                );

                $validatedAt = now();
                $locked->validated_at = $validatedAt;
                $locked->validated_by = $actor->getKey();

                $deliveryNote = $this->print->deliveryNote($locked);
                $packingList = $this->print->packingList($locked);

                $documents = [
                    $this->storeDocument(
                        $locked,
                        GeneratedDocumentType::DeliveryNote,
                        $deliveryNote,
                        $storedPaths,
                    ),
                    $this->storeDocument(
                        $locked,
                        GeneratedDocumentType::PackingList,
                        $packingList,
                        $storedPaths,
                    ),
                ];

                $generated = $this->archive->archiveStoredForShipment($locked, $documents, $actor);

                $locked->status = ShipmentStatus::Validated;
                $locked->validated_by = $actor->getKey();
                $locked->validated_at = $validatedAt;

                try {
                    $locked->save();
                } catch (QueryException $exception) {
                    if (str_contains($exception->getMessage(), 'delivery_note_reference')) {
                        throw ValidationException::withMessages([
                            'delivery_note_reference' => 'Cette référence de bon de livraison existe déjà pour cet OF.',
                        ]);
                    }

                    throw $exception;
                }

                $this->audit->record(
                    $locked,
                    ShipmentAuditEvent::Validated,
                    $actor,
                    ShipmentStatus::Prepared->value,
                    ShipmentStatus::Validated->value,
                    null,
                    [
                        'validated_at' => $validatedAt->toIso8601String(),
                        'delivery_note_reference' => $locked->delivery_note_reference,
                        'documents' => $generated->map(fn ($document): array => [
                            'id' => (int) $document->getKey(),
                            'type' => $document->type->value,
                            'reference' => $document->reference,
                            'sha256' => $document->sha256,
                        ])->values()->all(),
                        'lines' => $lines->map(fn (ShipmentLine $line): array => [
                            'shipment_line_id' => (int) $line->getKey(),
                            'work_order_item_id' => (int) $line->work_order_item_id,
                            'mark' => $line->mark_snapshot,
                            'quantity_prepared' => $line->quantity_prepared,
                        ])->values()->all(),
                    ],
                );

                return $locked->refresh();
            }, attempts: 1);
        } catch (Throwable $exception) {
            // Écriture durable hors transaction, puis miroir SQL en meilleur
            // effort. Une transaction externe peut annuler le miroir, jamais
            // le fichier append-only. L'audit précède le nettoyage des fichiers
            // pour ne pas être perdu si ce nettoyage échoue.
            $this->refusals->record($shipment, $actor, $exception, $attemptId);

            foreach ($storedPaths as [$disk, $path]) {
                try {
                    $this->storage->delete($disk, $path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);

            throw ValidationException::withMessages([
                'validation' => "L'expédition n'a pas pu être validée.",
            ]);
        }
    }

    /**
     * @param  array{contents: string, data: array<string, mixed>, source_data_hash: string, template_version: string}  $printed
     * @param  array<int, array{0: string, 1: string}>  $storedPaths
     * @return array{type: GeneratedDocumentType, stored: array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int, sha256: string}, metadata: array<string, mixed>}
     */
    protected function storeDocument(
        Shipment $shipment,
        GeneratedDocumentType $type,
        array $printed,
        array &$storedPaths,
    ): array {
        $stored = $this->storage->store(
            $printed['contents'],
            'shipments/'.$shipment->getKey().'/'.$type->value,
            $shipment->number.'-'.$type->getReferencePrefix().'.pdf',
        );

        $storedPaths[] = [$stored['disk'], $stored['path']];

        return [
            'type' => $type,
            'stored' => $stored,
            'metadata' => [
                'template_version' => $printed['template_version'],
                'source_data_hash' => $printed['source_data_hash'],
                'of_revision' => $printed['data']['of_revision'] ?? null,
                'revision_snapshot' => $printed['data']['revision_snapshot'] ?? null,
                'notes' => null,
            ],
        ];
    }

    protected function ensureActor(?User $actor): void
    {
        if ($actor === null || ! $actor->exists) {
            throw ValidationException::withMessages([
                'user' => 'Un utilisateur autorisé et enregistré est requis.',
            ]);
        }
    }

    protected function assertDeliveryNoteReferenceAvailable(
        int $workOrderId,
        ?string $reference,
        ?int $ignoreShipmentId = null,
    ): void {
        if ($reference === null || trim($reference) === '') {
            return;
        }

        $exists = Shipment::query()
            ->where('work_order_id', $workOrderId)
            ->where('delivery_note_reference', $reference)
            ->when(
                $ignoreShipmentId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreShipmentId),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'delivery_note_reference' => 'Cette référence de bon de livraison existe déjà pour cet OF.',
            ]);
        }
    }
}
