<?php

namespace App\Services;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\ShipmentAuditEvent;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\GeneratedDocument;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Création, préparation, validation et suivi des expéditions.
 *
 * La validation définitive et le départ effectif sont des opérations
 * distinctes, réservées à l'administrateur. Le Lot 4 ajoute le départ et
 * l'annulation contrôlée ; le Lot 5 n'est pas engagé.
 */
class ShipmentService
{
    public function __construct(
        protected ShipmentNumberGenerator $numbers,
        protected ShipmentAuditService $audit,
        protected ShipmentQuantityGuard $quantities,
        protected ShipmentDocumentService $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(WorkOrder $workOrder, ?User $actor = null, array $data = []): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('create', Shipment::class);

        return DB::transaction(function () use ($workOrder, $actor, $data): Shipment {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());

            $this->assertPreparable($locked);

            $deliveryNoteReference = $this->normalize($data['delivery_note_reference'] ?? null);
            $this->assertDeliveryNoteReferenceAvailable($locked->getKey(), $deliveryNoteReference);

            try {
                $shipment = Shipment::query()->create([
                    'work_order_id' => $locked->getKey(),
                    'number' => $this->numbers->nextNumber(),
                    'status' => ShipmentStatus::Draft,
                    'planned_ship_at' => $this->normalize($data['planned_ship_at'] ?? null),
                    'carrier_name' => $this->normalize($data['carrier_name'] ?? null),
                    'carrier_reference' => $this->normalize($data['carrier_reference'] ?? null),
                    'delivery_note_reference' => $deliveryNoteReference,
                    'delivery_address' => $this->normalize($data['delivery_address'] ?? $locked->job?->site_address),
                    'notes' => $this->normalize($data['notes'] ?? null),
                    'created_by' => $actor->getKey(),
                ]);
            } catch (QueryException $exception) {
                $this->throwIfDuplicateDeliveryNote($exception);

                throw $exception;
            }

            $this->audit->record(
                $shipment,
                ShipmentAuditEvent::Created,
                $actor,
                null,
                ShipmentStatus::Draft->value,
                $shipment->notes,
            );

            return $shipment->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(Shipment $shipment, array $data, ?User $actor = null): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $shipment);

        return DB::transaction(function () use ($shipment, $data, $actor): Shipment {
            $locked = $this->lockShipment($shipment);
            $this->assertEditable($locked);

            $before = $locked->only([
                'planned_ship_at',
                'carrier_name',
                'carrier_reference',
                'delivery_note_reference',
                'delivery_address',
                'notes',
            ]);

            $deliveryNoteReference = array_key_exists('delivery_note_reference', $data)
                ? $this->normalize($data['delivery_note_reference'])
                : $locked->delivery_note_reference;

            $this->assertDeliveryNoteReferenceAvailable(
                $locked->work_order_id,
                $deliveryNoteReference,
                $locked->getKey(),
            );

            $locked->fill([
                'planned_ship_at' => array_key_exists('planned_ship_at', $data)
                    ? $this->normalize($data['planned_ship_at'])
                    : $locked->planned_ship_at,
                'carrier_name' => array_key_exists('carrier_name', $data)
                    ? $this->normalize($data['carrier_name'])
                    : $locked->carrier_name,
                'carrier_reference' => array_key_exists('carrier_reference', $data)
                    ? $this->normalize($data['carrier_reference'])
                    : $locked->carrier_reference,
                'delivery_note_reference' => $deliveryNoteReference,
                'delivery_address' => array_key_exists('delivery_address', $data)
                    ? $this->normalize($data['delivery_address'])
                    : $locked->delivery_address,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalize($data['notes'])
                    : $locked->notes,
            ]);

            try {
                $locked->save();
            } catch (QueryException $exception) {
                $this->throwIfDuplicateDeliveryNote($exception);

                throw $exception;
            }

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::Updated,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                ['before' => $before, 'after' => $locked->only(array_keys($before))],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addLine(
        Shipment $shipment,
        WorkOrderItem $item,
        int $quantityPrepared,
        ?User $actor = null,
        array $data = [],
    ): ShipmentLine {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $shipment);

        return DB::transaction(function () use ($shipment, $item, $quantityPrepared, $actor, $data): ShipmentLine {
            $locked = $this->lockShipment($shipment);
            $this->assertEditable($locked);

            if ((int) $item->work_order_id !== (int) $locked->work_order_id) {
                throw ValidationException::withMessages([
                    'work_order_item_id' => 'La pièce n\'appartient pas à l\'OF de cette expédition.',
                ]);
            }

            if ($quantityPrepared < 1) {
                throw ValidationException::withMessages([
                    'quantity_prepared' => 'La quantité préparée doit être strictement positive.',
                ]);
            }

            if (ShipmentLine::query()
                ->where('shipment_id', $locked->getKey())
                ->where('work_order_item_id', $item->getKey())
                ->exists()) {
                throw ValidationException::withMessages([
                    'work_order_item_id' => 'Ce repère possède déjà une ligne dans cette expédition.',
                ]);
            }

            $line = ShipmentLine::query()->create([
                'shipment_id' => $locked->getKey(),
                'work_order_item_id' => $item->getKey(),
                'mark_snapshot' => $item->mark,
                'designation_snapshot' => $item->designation,
                'quantity_prepared' => $quantityPrepared,
                'quantity_shipped' => 0,
                'unit_weight_kg_snapshot' => $item->unit_weight_kg,
                'notes' => $this->normalize($data['notes'] ?? null),
            ]);

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::LineAdded,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                [
                    'shipment_line_id' => (int) $line->getKey(),
                    'work_order_item_id' => (int) $item->getKey(),
                    'mark' => $line->mark_snapshot,
                    'quantity_prepared' => $line->quantity_prepared,
                    'accepted_quantity' => $this->quantities->acceptedQuantity($item),
                    'remaining_to_prepare' => $this->quantities->remainingToPrepareForItem($item),
                ],
            );

            return $line->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLine(
        ShipmentLine $line,
        int $quantityPrepared,
        ?User $actor = null,
        array $data = [],
    ): ShipmentLine {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $line->shipment);

        return DB::transaction(function () use ($line, $quantityPrepared, $actor, $data): ShipmentLine {
            $lockedShipment = $this->lockShipment($line->shipment);
            $this->assertEditable($lockedShipment);

            $lockedLine = ShipmentLine::query()->lockForUpdate()->findOrFail($line->getKey());

            if ($quantityPrepared < 1) {
                throw ValidationException::withMessages([
                    'quantity_prepared' => 'La quantité préparée doit être strictement positive.',
                ]);
            }

            $packaged = $this->quantities->packagedForLine($lockedLine);

            if ($quantityPrepared < $packaged) {
                throw ValidationException::withMessages([
                    'quantity_prepared' => "La quantité préparée ne peut pas être inférieure aux {$packaged} pièces déjà réparties dans les colis.",
                ]);
            }

            $before = $lockedLine->quantity_prepared;

            $lockedLine->fill([
                'quantity_prepared' => $quantityPrepared,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalize($data['notes'])
                    : $lockedLine->notes,
            ]);
            $lockedLine->save();

            $this->audit->record(
                $lockedShipment,
                ShipmentAuditEvent::LineUpdated,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                [
                    'shipment_line_id' => (int) $lockedLine->getKey(),
                    'before' => $before,
                    'after' => $lockedLine->quantity_prepared,
                ],
            );

            return $lockedLine->refresh();
        }, attempts: 3);
    }

    public function removeLine(ShipmentLine $line, ?User $actor = null): void
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('update', $line->shipment);

        DB::transaction(function () use ($line, $actor): void {
            $lockedShipment = $this->lockShipment($line->shipment);
            $this->assertEditable($lockedShipment);

            $lockedLine = ShipmentLine::query()->lockForUpdate()->findOrFail($line->getKey());
            $metadata = [
                'shipment_line_id' => (int) $lockedLine->getKey(),
                'mark' => $lockedLine->mark_snapshot,
                'quantity_prepared' => $lockedLine->quantity_prepared,
            ];

            $lockedLine->delete();

            $this->audit->record(
                $lockedShipment,
                ShipmentAuditEvent::LineRemoved,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Draft->value,
                null,
                $metadata,
            );
        }, attempts: 3);
    }

    public function prepare(Shipment $shipment, ?User $actor = null): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('prepare', $shipment);

        return DB::transaction(function () use ($shipment, $actor): Shipment {
            $locked = $this->lockShipment($shipment);

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une expédition brouillon d’un OF non annulé peut être préparée.',
                ]);
            }

            $locked->status = ShipmentStatus::Prepared;
            $locked->prepared_by = $actor->getKey();
            $locked->prepared_at = now();
            $locked->save();

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::Prepared,
                $actor,
                ShipmentStatus::Draft->value,
                ShipmentStatus::Prepared->value,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function reopen(Shipment $shipment, ?User $actor = null): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('reopen', $shipment);

        return DB::transaction(function () use ($shipment, $actor): Shipment {
            $locked = $this->lockShipment($shipment);

            if (! $locked->canReopen()) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une expédition préparée d’un OF non annulé peut être rouverte.',
                ]);
            }

            $locked->status = ShipmentStatus::Draft;
            $locked->prepared_by = null;
            $locked->prepared_at = null;
            $locked->save();

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::Reopened,
                $actor,
                ShipmentStatus::Prepared->value,
                ShipmentStatus::Draft->value,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function ship(Shipment $shipment, ?User $actor = null, ?string $comment = null): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('ship', $shipment);

        $comment = $comment === null ? null : trim($comment);

        if ($comment === '') {
            $comment = null;
        }

        if ($comment !== null && mb_strlen($comment) > 10000) {
            throw ValidationException::withMessages([
                'comment' => 'L’observation de départ ne peut pas dépasser 10 000 caractères.',
            ]);
        }

        return DB::transaction(function () use ($shipment, $actor, $comment): Shipment {
            $locked = $this->lockShipment($shipment);

            if ($locked->status === ShipmentStatus::Shipped) {
                throw ValidationException::withMessages([
                    'status' => 'Cette expédition a déjà été expédiée.',
                ]);
            }

            if ($locked->status !== ShipmentStatus::Validated) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une expédition validée peut être expédiée.',
                ]);
            }

            if ($locked->workOrder?->status === WorkOrderStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'work_order' => 'L’OF de cette expédition est annulé.',
                ]);
            }

            if ($locked->workOrder === null) {
                throw ValidationException::withMessages([
                    'work_order' => 'L’OF de cette expédition est introuvable.',
                ]);
            }

            // Ordre de verrouillage : OF → expédition → Job → Documents
            // applicables (ID croissant). Les documents restent verrouillés
            // jusqu'au commit, ce qui bloque toute approbation concurrente
            // d'une nouvelle version.
            $this->documents->assertDocumentSituationDecided($locked->workOrder, $locked);

            $lines = ShipmentLine::query()
                ->where('shipment_id', $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'preparation' => 'Une expédition sans ligne ne peut pas partir.',
                ]);
            }

            $shippedAt = now();

            foreach ($lines as $line) {
                $line->quantity_shipped = $line->quantity_prepared;
                $line->save();
            }

            $locked->status = ShipmentStatus::Shipped;
            $locked->actual_ship_at = $shippedAt;
            $locked->shipped_by = $actor->getKey();
            $locked->save();

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::Shipped,
                $actor,
                ShipmentStatus::Validated->value,
                ShipmentStatus::Shipped->value,
                $comment,
                [
                    'actual_ship_at' => $shippedAt->toIso8601String(),
                    'lines' => $lines->map(fn (ShipmentLine $line): array => [
                        'shipment_line_id' => (int) $line->getKey(),
                        'mark' => $line->mark_snapshot,
                        'quantity_shipped' => $line->quantity_shipped,
                    ])->values()->all(),
                ],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function cancel(Shipment $shipment, ?User $actor = null, string $reason = ''): Shipment
    {
        $this->ensureActor($actor);
        Gate::forUser($actor)->authorize('cancel', $shipment);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => 'Un motif d’annulation est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($shipment, $actor, $reason): Shipment {
            $locked = $this->lockShipment($shipment);

            if (! $locked->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Cette expédition ne peut pas être annulée dans son état actuel.',
                ]);
            }

            if ($locked->status === ShipmentStatus::Validated && mb_strlen($reason) < 20) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Le motif d’annulation d’une expédition validée doit contenir au moins 20 caractères.',
                ]);
            }

            $documents = $locked->generatedDocuments()->get();
            $fromStatus = $locked->status->value;

            $locked->status = ShipmentStatus::Cancelled;
            $locked->cancelled_by = $actor->getKey();
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            foreach ($documents as $document) {
                if ($document->status === GeneratedDocumentStatus::Archived) {
                    $document->status = GeneratedDocumentStatus::Cancelled;
                    $document->save();
                }
            }

            $this->audit->record(
                $locked,
                ShipmentAuditEvent::Cancelled,
                $actor,
                $fromStatus,
                ShipmentStatus::Cancelled->value,
                $reason,
                [
                    'cancelled_at' => $locked->cancelled_at?->toIso8601String(),
                    'documents' => $documents->map(fn (GeneratedDocument $document): array => [
                        'id' => (int) $document->getKey(),
                        'reference' => $document->reference,
                    ])->values()->all(),
                ],
            );

            if ($documents->isNotEmpty()) {
                $this->audit->record(
                    $locked,
                    ShipmentAuditEvent::DocumentCancelled,
                    $actor,
                    null,
                    null,
                    'Documents d’expédition annulés, fichiers conservés.',
                    [
                        'documents' => $documents->map(fn (GeneratedDocument $document): array => [
                            'id' => (int) $document->getKey(),
                            'reference' => $document->reference,
                        ])->values()->all(),
                    ],
                );
            }

            return $locked->refresh();
        }, attempts: 3);
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

    protected function throwIfDuplicateDeliveryNote(QueryException $exception): void
    {
        if (! str_contains($exception->getMessage(), 'delivery_note_reference')) {
            return;
        }

        throw ValidationException::withMessages([
            'delivery_note_reference' => 'Cette référence de bon de livraison existe déjà pour cet OF.',
        ]);
    }

    protected function lockShipment(Shipment $shipment): Shipment
    {
        $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($shipment->work_order_id);

        $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());
        $locked->setRelation('workOrder', $workOrder);

        return $locked;
    }

    protected function assertPreparable(WorkOrder $workOrder): void
    {
        $allowed = [
            WorkOrderStatus::Released,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::QualityCheck,
            WorkOrderStatus::Completed,
        ];

        if (! in_array($workOrder->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'work_order_id' => "Une expédition ne peut pas être préparée pour un OF à l'état « {$workOrder->status->getLabel()} ».",
            ]);
        }
    }

    protected function assertEditable(Shipment $shipment): void
    {
        if (! $shipment->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Seule une expédition brouillon peut être modifiée. Rouvrez la préparation.',
            ]);
        }
    }

    protected function ensureActor(?User $actor): void
    {
        if ($actor === null || ! $actor->exists) {
            throw ValidationException::withMessages([
                'user' => 'Un utilisateur autorisé et enregistré est requis.',
            ]);
        }
    }

    protected function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value === '' ? null : $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
