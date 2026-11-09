<?php

namespace App\Services;

use App\DTO\WorkOrderItemSummary;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderItemStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Service unique de gestion des pièces et repères d'un ordre de fabrication.
 *
 * Toutes les mutations verrouillent d'abord l'OF puis les pièces, dans un ordre
 * déterministe (identifiants croissants), et écrivent leur audit dans la même
 * transaction métier.
 */
class WorkOrderItemService
{
    /**
     * Champs modifiables par un formulaire ou un import.
     *
     * @var array<int, string>
     */
    protected const EDITABLE_FIELDS = [
        'mark',
        'designation',
        'profile',
        'steel_grade',
        'length_mm',
        'quantity_planned',
        'unit_weight_kg',
        'operation_group',
        'notes',
    ];

    public function __construct(
        protected WorkOrderService $workOrders,
        protected WorkOrderAuditService $auditLog,
    ) {}

    /**
     * Crée une pièce. Si le repère existe déjà et que les caractéristiques
     * techniques sont identiques, les deux lignes sont fusionnées et seule la
     * quantité prévue est additionnée.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(WorkOrder $workOrder, array $data, ?User $actor = null): WorkOrderItem
    {
        $this->assertNoProtectedFields($data);

        $validated = $this->validate($data);

        return DB::transaction(function () use ($workOrder, $validated, $actor): WorkOrderItem {
            $lockedWorkOrder = $this->workOrders->lockForMutation($workOrder);

            $mark = WorkOrderItem::normalizeMark((string) $validated['mark']);

            $existing = WorkOrderItem::query()
                ->where('work_order_id', $lockedWorkOrder->getKey())
                ->where('mark', $mark)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status->isFinal()) {
                    $this->fail(
                        'mark',
                        "Le repère {$existing->mark} existe déjà sur une pièce « {$existing->status->getLabel()} » et ne peut plus être fusionné.",
                    );
                }

                $this->assertMergeCompatible(
                    $existing,
                    $this->technicalIdentityFromAttributes($validated),
                );

                $plannedAdded = (int) $validated['quantity_planned'];
                $existing->quantity_planned += $plannedAdded;
                $existing->save();

                $this->synchronizeProductionStatus($lockedWorkOrder, $existing, $actor);

                $this->audit(
                    $lockedWorkOrder,
                    WorkOrderAuditEvent::ItemMerged,
                    $actor,
                    $existing,
                    [
                        'planned_added' => $plannedAdded,
                        'quantity_planned' => $existing->quantity_planned,
                    ],
                );

                return $existing->refresh();
            }

            $item = $lockedWorkOrder->items()->create(
                Arr::only($validated, self::EDITABLE_FIELDS),
            );

            $this->audit(
                $lockedWorkOrder,
                WorkOrderAuditEvent::ItemCreated,
                $actor,
                $item,
                ['quantity_planned' => $item->quantity_planned],
            );

            return $item->refresh();
        }, attempts: 3);
    }

    /**
     * Modifie une pièce. Un changement de repère vers un repère existant est
     * fusionné uniquement si les caractéristiques techniques sont identiques
     * et si la pièce source n'a pas déjà de production enregistrée.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(WorkOrderItem $item, array $data, ?User $actor = null): WorkOrderItem
    {
        $this->assertNoProtectedFields($data);

        $validated = $this->validate($data, partial: true);

        return DB::transaction(function () use ($item, $validated, $actor): WorkOrderItem {
            $lockedWorkOrder = $this->lockWorkOrderForItem($item);

            $targetMark = array_key_exists('mark', $validated)
                ? WorkOrderItem::normalizeMark((string) $validated['mark'])
                : $item->mark;

            // Verrouillage déterministe : OF puis pièces par identifiant croissant.
            $items = WorkOrderItem::query()
                ->where('work_order_id', $lockedWorkOrder->getKey())
                ->where(function ($query) use ($item, $targetMark): void {
                    $query->whereKey($item->getKey())
                        ->orWhere('mark', $targetMark);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $source = $items->firstWhere('id', $item->getKey());

            if ($source === null) {
                $this->fail('item', "Cette pièce n'appartient pas à cet ordre de fabrication.");
            }

            if ($source->status->isFinal()) {
                $this->fail(
                    'status',
                    "Une pièce « {$source->status->getLabel()} » ne peut plus être modifiée.",
                );
            }

            $target = $items->firstWhere('mark', $targetMark);

            if ($target !== null && $target->getKey() !== $source->getKey()) {
                if ($target->status->isFinal()) {
                    $this->fail(
                        'mark',
                        "Le repère {$target->mark} existe déjà sur une pièce « {$target->status->getLabel()} » et ne peut plus être fusionné.",
                    );
                }

                $candidateIdentity = $this->technicalIdentityWithOverrides($source, $validated);

                $this->assertMergeCompatible($target, $candidateIdentity);

                if ($source->hasRecordedProduction()) {
                    $this->fail(
                        'mark',
                        'Une pièce ayant déjà une production ou des rebuts ne peut pas être fusionnée avec un autre repère.',
                    );
                }

                $plannedAdded = array_key_exists('quantity_planned', $validated)
                    ? (int) $validated['quantity_planned']
                    : $source->quantity_planned;

                $sourceMark = $source->mark;

                $target->quantity_planned += $plannedAdded;
                $target->save();

                $this->synchronizeProductionStatus($lockedWorkOrder, $target, $actor);

                $source->delete();

                $this->audit(
                    $lockedWorkOrder,
                    WorkOrderAuditEvent::ItemMerged,
                    $actor,
                    $target,
                    [
                        'source_mark' => $sourceMark,
                        'planned_added' => $plannedAdded,
                        'quantity_planned' => $target->quantity_planned,
                    ],
                );

                return $target->refresh();
            }

            $source->fill(Arr::only($validated, self::EDITABLE_FIELDS));
            $changedFields = array_keys($source->getDirty());

            if ($changedFields === []) {
                return $source->refresh();
            }

            $source->save();

            $this->synchronizeProductionStatus($lockedWorkOrder, $source, $actor);

            $this->audit(
                $lockedWorkOrder,
                WorkOrderAuditEvent::ItemUpdated,
                $actor,
                $source,
                ['changed_fields' => $changedFields],
            );

            return $source->refresh();
        }, attempts: 3);
    }

    /**
     * Supprime une pièce non produite. Une pièce ayant déjà une quantité
     * fabriquée ou rebutée doit être annulée, jamais supprimée.
     */
    public function delete(WorkOrderItem $item, ?User $actor = null): void
    {
        DB::transaction(function () use ($item, $actor): void {
            $lockedWorkOrder = $this->lockWorkOrderForItem($item);
            $lockedItem = $this->lockItem($lockedWorkOrder, $item);

            if ($lockedItem->status->isFinal()) {
                $this->fail(
                    'status',
                    "Une pièce « {$lockedItem->status->getLabel()} » ne peut pas être supprimée.",
                );
            }

            if ($lockedItem->hasRecordedProduction()) {
                $this->fail(
                    'status',
                    'Une pièce ayant déjà une quantité fabriquée ou rebutée ne peut pas être supprimée. Utilisez l’annulation.',
                );
            }

            $mark = $lockedItem->mark;

            $lockedItem->delete();

            $this->audit(
                $lockedWorkOrder,
                WorkOrderAuditEvent::ItemDeleted,
                $actor,
                $lockedItem,
                ['mark' => $mark],
            );
        }, attempts: 3);
    }

    /**
     * Enregistre la production totale et les rebuts. La quantité acceptée,
     * et non la quantité produite, détermine si la pièce est fabriquée.
     */
    public function recordProduction(
        WorkOrderItem $item,
        int $quantityProduced,
        int $quantityRejected,
        ?User $actor = null,
    ): WorkOrderItem {
        $validated = Validator::make(
            [
                'quantity_produced' => $quantityProduced,
                'quantity_rejected' => $quantityRejected,
            ],
            [
                'quantity_produced' => ['required', 'integer', 'min:0', 'max:4294967295'],
                'quantity_rejected' => ['required', 'integer', 'min:0', 'max:4294967295'],
            ],
        )->validate();

        $produced = (int) $validated['quantity_produced'];
        $rejected = (int) $validated['quantity_rejected'];

        if ($rejected > $produced) {
            $this->fail(
                'quantity_rejected',
                'La quantité rebutée ne peut pas dépasser la quantité fabriquée.',
            );
        }

        return DB::transaction(function () use ($item, $produced, $rejected, $actor): WorkOrderItem {
            $lockedWorkOrder = $this->lockWorkOrderForItem($item);
            $lockedItem = $this->lockItem($lockedWorkOrder, $item);

            if ($lockedItem->status->isFinal()) {
                $this->fail(
                    'status',
                    "Une pièce « {$lockedItem->status->getLabel()} » ne peut plus recevoir de production.",
                );
            }

            $previousStatus = $lockedItem->status;
            $targetStatus = $this->statusForQuantities($lockedItem, $produced, $rejected);

            if ($targetStatus !== $previousStatus && ! $previousStatus->canTransitionTo($targetStatus)) {
                $this->fail(
                    'status',
                    "Transition de statut interdite : « {$previousStatus->getLabel()} » vers « {$targetStatus->getLabel()} ».",
                );
            }

            $previousProduced = $lockedItem->quantity_produced;
            $previousRejected = $lockedItem->quantity_rejected;

            if (
                $previousProduced === $produced
                && $previousRejected === $rejected
                && $previousStatus === $targetStatus
            ) {
                return $lockedItem->refresh();
            }

            $lockedItem->quantity_produced = $produced;
            $lockedItem->quantity_rejected = $rejected;
            $lockedItem->status = $targetStatus;
            $lockedItem->save();

            $this->audit(
                $lockedWorkOrder,
                WorkOrderAuditEvent::ItemProductionRecorded,
                $actor,
                $lockedItem,
                [
                    'previous_produced' => $previousProduced,
                    'previous_rejected' => $previousRejected,
                    'quantity_produced' => $lockedItem->quantity_produced,
                    'quantity_rejected' => $lockedItem->quantity_rejected,
                    'accepted_quantity' => $lockedItem->accepted_quantity,
                    'previous_status' => $previousStatus->value,
                    'status' => $targetStatus->value,
                ],
            );

            return $lockedItem->refresh();
        }, attempts: 3);
    }

    /**
     * Démarre explicitement une pièce sans saisir de production.
     */
    public function start(WorkOrderItem $item, ?User $actor = null): WorkOrderItem
    {
        return DB::transaction(function () use ($item, $actor): WorkOrderItem {
            $lockedWorkOrder = $this->lockWorkOrderForItem($item);
            $lockedItem = $this->lockItem($lockedWorkOrder, $item);

            if ($lockedItem->status === WorkOrderItemStatus::InProgress) {
                return $lockedItem->refresh();
            }

            if ($lockedItem->status !== WorkOrderItemStatus::Todo) {
                $this->fail(
                    'status',
                    'Seule une pièce « À fabriquer » peut être démarrée.',
                );
            }

            return $this->transition($lockedWorkOrder, $lockedItem, WorkOrderItemStatus::InProgress, $actor);
        }, attempts: 3);
    }

    /**
     * Clôture explicitement une pièce entièrement fabriquée.
     */
    public function close(WorkOrderItem $item, ?User $actor = null): WorkOrderItem
    {
        return DB::transaction(function () use ($item, $actor): WorkOrderItem {
            $lockedWorkOrder = $this->lockWorkOrderForItem($item);
            $lockedItem = $this->lockItem($lockedWorkOrder, $item);

            if ($lockedItem->status === WorkOrderItemStatus::Closed) {
                return $lockedItem->refresh();
            }

            if ($lockedItem->status !== WorkOrderItemStatus::Produced) {
                $this->fail(
                    'status',
                    'Une pièce ne peut être clôturée que lorsqu’elle est entièrement fabriquée.',
                );
            }

            if ($lockedItem->accepted_quantity < $lockedItem->quantity_planned) {
                $this->fail(
                    'status',
                    'La quantité acceptée doit atteindre la quantité prévue avant la clôture.',
                );
            }

            return $this->transition($lockedWorkOrder, $lockedItem, WorkOrderItemStatus::Closed, $actor);
        }, attempts: 3);
    }

    /**
     * Annule explicitement une pièce non clôturée.
     */
    public function cancel(WorkOrderItem $item, ?User $actor = null): WorkOrderItem
    {
        return DB::transaction(function () use ($item, $actor): WorkOrderItem {
            $lockedWorkOrder = $this->lockWorkOrderForItem($item);
            $lockedItem = $this->lockItem($lockedWorkOrder, $item);

            if ($lockedItem->status === WorkOrderItemStatus::Cancelled) {
                return $lockedItem->refresh();
            }

            if ($lockedItem->status === WorkOrderItemStatus::Closed) {
                $this->fail('status', 'Une pièce clôturée ne peut pas être annulée.');
            }

            return $this->transition($lockedWorkOrder, $lockedItem, WorkOrderItemStatus::Cancelled, $actor);
        }, attempts: 3);
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    public function all(WorkOrder $workOrder): Collection
    {
        return $workOrder->items()->orderBy('mark')->get();
    }

    public function find(WorkOrder $workOrder, int $itemId): WorkOrderItem
    {
        $item = $workOrder->items()->whereKey($itemId)->first();

        if ($item === null) {
            $this->fail('item', "Cette pièce n'appartient pas à cet ordre de fabrication.");
        }

        return $item;
    }

    /**
     * Groupes d'opérations présents dans la nomenclature de l'OF.
     *
     * @return array<string, string>
     */
    public function operationGroups(WorkOrder $workOrder): array
    {
        return $workOrder->items()
            ->whereNotNull('operation_group')
            ->where('operation_group', '!=', '')
            ->distinct()
            ->orderBy('operation_group')
            ->pluck('operation_group', 'operation_group')
            ->all();
    }

    public function summary(WorkOrder $workOrder): WorkOrderItemSummary
    {
        $items = $workOrder->items()->get();

        return new WorkOrderItemSummary(
            planned: (int) $items->sum('quantity_planned'),
            produced: (int) $items->sum('quantity_produced'),
            accepted: (int) $items->sum(fn (WorkOrderItem $item): int => $item->accepted_quantity),
            rejected: (int) $items->sum('quantity_rejected'),
            remaining: (int) $items->sum(fn (WorkOrderItem $item): int => $item->remaining_quantity),
            totalWeightKg: round(
                (float) $items->sum(fn (WorkOrderItem $item): float => (float) ($item->total_weight_kg ?? 0)),
                3,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return Validator::make($data, [
            'mark' => [...$required, 'string', 'max:100'],
            'designation' => [...$required, 'string', 'max:255'],
            'profile' => ['sometimes', 'nullable', 'string', 'max:100'],
            'steel_grade' => ['sometimes', 'nullable', 'string', 'max:100'],
            'length_mm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'quantity_planned' => [...$required, 'integer', 'min:0', 'max:4294967295'],
            'unit_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'operation_group' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNoProtectedFields(array $data): void
    {
        $forbidden = array_values(array_intersect(
            WorkOrderItem::PROTECTED_FIELDS,
            array_keys($data),
        ));

        if ($forbidden !== []) {
            $this->fail(
                'item',
                'Champ(s) non modifiable(s) directement : '.implode(', ', $forbidden).'.',
            );
        }
    }

    protected function lockWorkOrderForItem(WorkOrderItem $item): WorkOrder
    {
        $workOrder = WorkOrder::query()->find($item->work_order_id);

        if ($workOrder === null) {
            $this->fail('work_order', "Cet ordre de fabrication n'existe plus.");
        }

        return $this->workOrders->lockForMutation($workOrder);
    }

    protected function lockItem(WorkOrder $lockedWorkOrder, WorkOrderItem $item): WorkOrderItem
    {
        $lockedItem = WorkOrderItem::query()
            ->where('work_order_id', $lockedWorkOrder->getKey())
            ->whereKey($item->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedItem === null) {
            $this->fail('item', "Cette pièce n'appartient pas à cet ordre de fabrication.");
        }

        return $lockedItem;
    }

    protected function transition(
        WorkOrder $lockedWorkOrder,
        WorkOrderItem $item,
        WorkOrderItemStatus $target,
        ?User $actor,
    ): WorkOrderItem {
        if ($item->status->isFinal()) {
            $this->fail(
                'status',
                "Une pièce « {$item->status->getLabel()} » ne peut plus changer d’état.",
            );
        }

        if (! $item->status->canTransitionTo($target)) {
            $this->fail(
                'status',
                "Transition de statut interdite : « {$item->status->getLabel()} » vers « {$target->getLabel()} ».",
            );
        }

        $previous = $item->status;
        $item->status = $target;
        $item->save();

        $this->audit(
            $lockedWorkOrder,
            WorkOrderAuditEvent::ItemStatusChanged,
            $actor,
            $item,
            [
                'previous_status' => $previous->value,
                'status' => $target->value,
            ],
        );

        return $item->refresh();
    }

    /**
     * Après une variation de quantité prévue, l'état de production doit
     * rester cohérent avec la quantité acceptée.
     */
    protected function synchronizeProductionStatus(
        WorkOrder $lockedWorkOrder,
        WorkOrderItem $item,
        ?User $actor,
    ): void {
        if ($item->status->isFinal()) {
            return;
        }

        $target = $this->statusForQuantities(
            $item,
            (int) $item->quantity_produced,
            (int) $item->quantity_rejected,
        );

        if ($target === $item->status) {
            return;
        }

        $this->transition($lockedWorkOrder, $item, $target, $actor);
    }

    protected function statusForQuantities(
        WorkOrderItem $item,
        int $produced,
        int $rejected,
    ): WorkOrderItemStatus {
        $accepted = max(0, $produced - $rejected);
        $planned = (int) $item->quantity_planned;

        if ($accepted >= $planned && ($planned > 0 || $accepted > 0)) {
            return WorkOrderItemStatus::Produced;
        }

        if ($produced > 0 || $rejected > 0) {
            return WorkOrderItemStatus::PartiallyProduced;
        }

        return $item->status === WorkOrderItemStatus::Todo
            ? WorkOrderItemStatus::Todo
            : WorkOrderItemStatus::InProgress;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{designation: ?string, profile: ?string, steel_grade: ?string, length_mm: ?string}
     */
    public function technicalIdentityFromAttributes(array $attributes): array
    {
        return [
            'designation' => WorkOrderItem::normalizeTechnicalText($attributes['designation'] ?? null),
            'profile' => WorkOrderItem::normalizeTechnicalText($attributes['profile'] ?? null),
            'steel_grade' => WorkOrderItem::normalizeTechnicalText($attributes['steel_grade'] ?? null),
            'length_mm' => $this->normalizedLength($attributes['length_mm'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{designation: ?string, profile: ?string, steel_grade: ?string, length_mm: ?string}
     */
    protected function technicalIdentityWithOverrides(WorkOrderItem $item, array $validated): array
    {
        return [
            'designation' => WorkOrderItem::normalizeTechnicalText(
                array_key_exists('designation', $validated) ? $validated['designation'] : $item->designation,
            ),
            'profile' => WorkOrderItem::normalizeTechnicalText(
                array_key_exists('profile', $validated) ? $validated['profile'] : $item->profile,
            ),
            'steel_grade' => WorkOrderItem::normalizeTechnicalText(
                array_key_exists('steel_grade', $validated) ? $validated['steel_grade'] : $item->steel_grade,
            ),
            'length_mm' => $this->normalizedLength(
                array_key_exists('length_mm', $validated) ? $validated['length_mm'] : $item->length_mm,
            ),
        ];
    }

    /**
     * Champs d'identité technique qui diffèrent entre une pièce existante et
     * une identité candidate. Utilisé par la fusion et par l'aperçu d'import.
     *
     * @param  array{designation: ?string, profile: ?string, steel_grade: ?string, length_mm: ?string}  $candidate
     * @return array<int, string>
     */
    public function identityDifferences(WorkOrderItem $existing, array $candidate): array
    {
        $existingIdentity = $existing->technicalIdentity();
        $differences = [];

        foreach ($candidate as $field => $value) {
            if (($existingIdentity[$field] ?? null) !== $value) {
                $differences[] = $field;
            }
        }

        return $differences;
    }

    /**
     * @param  array<int, string>  $differences
     */
    public function mergeConflictMessage(WorkOrderItem $existing, array $differences): string
    {
        return "Le repère {$existing->mark} existe déjà dans cet OF avec des caractéristiques techniques différentes (champs : "
            .implode(', ', $differences).'). La fusion est refusée.';
    }

    /**
     * @param  array{designation: ?string, profile: ?string, steel_grade: ?string, length_mm: ?string}  $candidate
     */
    protected function assertMergeCompatible(WorkOrderItem $existing, array $candidate): void
    {
        $differences = $this->identityDifferences($existing, $candidate);

        if ($differences !== []) {
            $this->fail('mark', $this->mergeConflictMessage($existing, $differences));
        }
    }

    protected function normalizedLength(mixed $length): ?string
    {
        if ($length === null || $length === '') {
            return null;
        }

        return number_format((float) $length, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(
        WorkOrder $workOrder,
        WorkOrderAuditEvent $event,
        ?User $actor,
        WorkOrderItem $item,
        array $metadata = [],
        ?string $fromStatus = null,
        ?string $toStatus = null,
    ): void {
        $this->auditLog->record(
            $workOrder,
            $event,
            $actor,
            $fromStatus,
            $toStatus,
            null,
            array_merge([
                'item_id' => $item->getKey(),
                'mark' => $item->mark,
            ], $metadata),
        );
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
