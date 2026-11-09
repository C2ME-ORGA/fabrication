<?php

namespace App\Services;

use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationAuditLog;
use App\Models\WorkshopRouting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Affectation d'une gamme atelier à un OF et génération de ses opérations.
 *
 * Les opérations générées sont des copies indépendantes : aucune
 * synchronisation ultérieure avec la gamme source n'est effectuée. Toutes les
 * mutations sont transactionnelles et verrouillent d'abord l'OF, puis la
 * gamme, puis les opérations.
 */
class WorkOrderRoutingService
{
    public function __construct(
        protected WorkOrderService $workOrders,
        protected WorkshopRoutingValidationService $routingValidation,
        protected WorkOrderOperationAuditService $audit,
    ) {}

    /**
     * Affecte une gamme à un OF qui n'en possède pas encore.
     */
    public function assign(WorkOrder $workOrder, WorkshopRouting $routing, ?User $actor = null): WorkOrder
    {
        return $this->affect($workOrder, $routing, $actor, replacement: false);
    }

    /**
     * Remplace la gamme d'un OF avant libération.
     */
    public function replace(WorkOrder $workOrder, WorkshopRouting $routing, ?User $actor = null): WorkOrder
    {
        return $this->affect($workOrder, $routing, $actor, replacement: true);
    }

    /**
     * Modifie une opération générée avant libération de l'OF.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateOperation(
        WorkOrderOperation $operation,
        array $data,
        ?User $actor = null,
    ): WorkOrderOperation {
        $validated = $this->validateOperationData($data, partial: true);

        return DB::transaction(function () use ($operation, $validated, $actor): WorkOrderOperation {
            $lockedWorkOrder = $this->lockWorkOrderForOperation($operation, $actor);
            $lockedOperation = $this->lockOperation($lockedWorkOrder, $operation);

            if (array_key_exists('code', $validated)) {
                $validated['code'] = $this->normalizeOperationCode($validated['code']);
                $this->assertOperationCodeAvailable(
                    $lockedWorkOrder,
                    $validated['code'],
                    (int) $lockedOperation->getKey(),
                );
            }

            $previous = [];

            foreach (array_keys($validated) as $field) {
                $previous[$field] = $lockedOperation->getAttribute($field);
            }

            $lockedOperation->fill($validated);
            $changed = array_keys($lockedOperation->getDirty());

            if ($changed === []) {
                return $lockedOperation->refresh();
            }

            $lockedOperation->save();

            $this->audit->record(
                $lockedOperation,
                $lockedWorkOrder,
                WorkshopOperationAuditEvent::Updated,
                $actor,
                $lockedOperation->status->value,
                $lockedOperation->status->value,
                null,
                [
                    'changed_fields' => $changed,
                    'previous' => Arr::only($previous, $changed),
                    'current' => Arr::only($lockedOperation->getAttributes(), $changed),
                ],
            );

            return $lockedOperation->refresh();
        }, attempts: 3);
    }

    /**
     * Remplace les dépendances d'une opération générée avant libération.
     *
     * @param  array<int, int|string>  $dependencyIds
     */
    public function syncOperationDependencies(
        WorkOrderOperation $operation,
        array $dependencyIds,
        ?User $actor = null,
    ): WorkOrderOperation {
        $dependencyIds = $this->normalizeIds($dependencyIds);

        if (in_array((int) $operation->getKey(), $dependencyIds, true)) {
            $this->fail('dependencies', 'Une opération ne peut pas dépendre d\'elle-même.');
        }

        return DB::transaction(function () use ($operation, $dependencyIds, $actor): WorkOrderOperation {
            $lockedWorkOrder = $this->lockWorkOrderForOperation($operation, $actor);
            $lockedOperations = $lockedWorkOrder->operations()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedOperation = $lockedOperations->firstWhere('id', $operation->getKey());

            if ($lockedOperation === null) {
                $this->fail('operation', 'Cette opération n\'appartient pas à cet ordre de fabrication.');
            }

            $operationIds = $lockedOperations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            if (array_diff($dependencyIds, $operationIds) !== []) {
                $this->fail('dependencies', 'Toutes les dépendances doivent appartenir à cet ordre de fabrication.');
            }

            $previousDependencyIds = $lockedOperation->dependencies()
                ->pluck('work_order_operations.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $adjacency = $this->operationAdjacency($operationIds);
            $adjacency[(int) $lockedOperation->getKey()] = $dependencyIds;

            $cycle = $this->findOperationCycle($operationIds, $adjacency);

            if ($cycle !== null) {
                $codes = $lockedOperations->pluck('code', 'id')->all();
                $labels = array_map(fn (int $id): string => $codes[$id] ?? "#{$id}", $cycle);

                $this->fail('dependencies', 'Dépendance cyclique détectée : '.implode(' → ', $labels).'.');
            }

            $lockedOperation->dependencies()->sync($dependencyIds);

            $this->audit->record(
                $lockedOperation,
                $lockedWorkOrder,
                WorkshopOperationAuditEvent::DependenciesModified,
                $actor,
                $lockedOperation->status->value,
                $lockedOperation->status->value,
                null,
                [
                    'previous_dependency_ids' => $previousDependencyIds,
                    'dependency_ids' => $dependencyIds,
                ],
            );

            return $lockedOperation->refresh();
        }, attempts: 3);
    }

    /**
     * Supprime une opération générée avant libération, si elle n'a aucun
     * historique d'exécution et aucune dépendance.
     */
    public function deleteOperation(WorkOrderOperation $operation, ?User $actor = null): void
    {
        DB::transaction(function () use ($operation, $actor): void {
            $lockedWorkOrder = $this->lockWorkOrderForOperation($operation, $actor);
            $lockedOperation = $this->lockOperation($lockedWorkOrder, $operation);

            if ($lockedOperation->dependencies()->exists() || $lockedOperation->dependents()->exists()) {
                $this->fail(
                    'operation',
                    'Cette opération possède des dépendances. Supprimez-les explicitement avant de supprimer l\'opération.',
                );
            }

            $this->assertOperationHasNoExecutionHistory($lockedOperation);

            $this->audit->record(
                $lockedOperation,
                $lockedWorkOrder,
                WorkshopOperationAuditEvent::Deleted,
                $actor,
                $lockedOperation->status->value,
                null,
                null,
                ['operation_id' => $lockedOperation->getKey()],
            );

            $lockedOperation->delete();
        }, attempts: 3);
    }

    /**
     * Affecte ou remplace la gamme d'un OF dans une transaction unique.
     */
    protected function affect(
        WorkOrder $workOrder,
        WorkshopRouting $routing,
        ?User $actor,
        bool $replacement,
    ): WorkOrder {
        return DB::transaction(function () use ($workOrder, $routing, $actor, $replacement): WorkOrder {
            $lockedWorkOrder = $this->workOrders->lockForMutation($workOrder);
            $this->assertCanManageRouting($lockedWorkOrder, $actor);

            $lockedRouting = $this->lockRouting($routing);

            if (! $lockedRouting->isActive()) {
                $this->fail(
                    'routing',
                    "La gamme {$lockedRouting->code} est inactive et ne peut pas être affectée à un OF.",
                );
            }

            $this->routingValidation->assertValidForAssignment($lockedRouting);

            $oldOperations = $lockedWorkOrder->operations()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $oldRoutingId = $lockedWorkOrder->workshop_routing_id;
            $sameRouting = $oldRoutingId !== null && (int) $oldRoutingId === (int) $lockedRouting->getKey();

            if (! $replacement && ($oldRoutingId !== null || $oldOperations->isNotEmpty())) {
                $this->fail(
                    'routing',
                    'Une gamme est déjà affectée à cet ordre de fabrication. Utilisez le remplacement.',
                );
            }

            if ($replacement && $oldRoutingId === null && $oldOperations->isEmpty()) {
                $this->fail('routing', 'Aucune gamme n\'est actuellement affectée à cet ordre de fabrication.');
            }

            if ($sameRouting) {
                $this->fail('routing', 'Cette gamme est déjà affectée à cet ordre de fabrication.');
            }

            $previousRoutingCode = $oldRoutingId === null
                ? null
                : WorkshopRouting::query()->whereKey($oldRoutingId)->value('code');

            if ($oldOperations->isNotEmpty()) {
                $this->assertOperationsCanBeReplaced($oldOperations);

                $this->audit->record(
                    null,
                    $lockedWorkOrder,
                    WorkshopOperationAuditEvent::RoutingOperationsDeleted,
                    $actor,
                    null,
                    null,
                    null,
                    [
                        'workshop_routing_id' => $oldRoutingId,
                        'workshop_routing_code' => $previousRoutingCode,
                        'deleted_operation_ids' => $oldOperations->pluck('id')->all(),
                        'deleted_operation_codes' => $oldOperations->pluck('code')->all(),
                    ],
                );

                foreach ($oldOperations as $oldOperation) {
                    $oldOperation->delete();
                }
            }

            $lockedWorkOrder->workshop_routing_id = $lockedRouting->getKey();
            $lockedWorkOrder->save();

            $generated = $this->generateOperations($lockedWorkOrder, $lockedRouting, $actor);
            $this->assertGenerationIntegrity($lockedWorkOrder, $lockedRouting, $generated);

            $this->audit->record(
                null,
                $lockedWorkOrder,
                $replacement
                    ? WorkshopOperationAuditEvent::RoutingChanged
                    : WorkshopOperationAuditEvent::RoutingApplied,
                $actor,
                null,
                null,
                null,
                [
                    'workshop_routing_id' => $lockedRouting->getKey(),
                    'workshop_routing_code' => $lockedRouting->code,
                    'previous_routing_id' => $oldRoutingId,
                    'previous_routing_code' => $previousRoutingCode,
                    'generated_operations' => $generated->count(),
                    'generated_operation_ids' => $generated->pluck('id')->all(),
                    'deleted_operations' => $oldOperations->count(),
                ],
            );

            return $lockedWorkOrder->refresh();
        }, attempts: 3);
    }

    /**
     * @return Collection<int, WorkOrderOperation>
     */
    protected function generateOperations(
        WorkOrder $workOrder,
        WorkshopRouting $routing,
        ?User $actor,
    ): Collection {
        $sourceOperations = $routing->operations()->with('dependencies')->get();

        /** @var array<int, WorkOrderOperation> $mapping */
        $mapping = [];
        /** @var Collection<int, WorkOrderOperation> $generated */
        $generated = new Collection;

        foreach ($sourceOperations as $sourceOperation) {
            $operation = $workOrder->operations()->create([
                'code' => $sourceOperation->code,
                'name' => $sourceOperation->name,
                'instructions' => $sourceOperation->instructions,
                'position' => $sourceOperation->position,
                'estimated_duration_minutes' => $sourceOperation->estimated_duration_minutes,
                'is_mandatory' => $sourceOperation->is_mandatory,
            ]);

            // La référence source et le statut sont protégés contre
            // l'assignation de masse : ils sont écrits explicitement.
            $operation->forceFill([
                'source_routing_operation_id' => $sourceOperation->getKey(),
                'status' => $sourceOperation->dependencies->isEmpty()
                    ? WorkshopOperationStatus::Ready
                    : WorkshopOperationStatus::Pending,
            ])->save();

            $mapping[(int) $sourceOperation->getKey()] = $operation;
            $generated->push($operation);
        }

        foreach ($sourceOperations as $sourceOperation) {
            $operation = $mapping[(int) $sourceOperation->getKey()];

            foreach ($sourceOperation->dependencies as $dependency) {
                $dependencyOperation = $mapping[(int) $dependency->getKey()] ?? null;

                if ($dependencyOperation === null) {
                    throw new RuntimeException(
                        "La dépendance {$dependency->code} n'a pas été copiée dans l'OF."
                    );
                }

                $operation->dependencies()->attach($dependencyOperation->getKey());
            }
        }

        foreach ($sourceOperations as $sourceOperation) {
            $operation = $mapping[(int) $sourceOperation->getKey()];

            $this->audit->record(
                $operation,
                $workOrder,
                WorkshopOperationAuditEvent::Created,
                $actor,
                null,
                $operation->status->value,
                null,
                [
                    'workshop_routing_id' => $routing->getKey(),
                    'workshop_routing_code' => $routing->code,
                    'source_routing_operation_id' => $sourceOperation->getKey(),
                    'source_operation_code' => $sourceOperation->code,
                    'dependencies_count' => $sourceOperation->dependencies->count(),
                ],
            );
        }

        return $generated;
    }

    /**
     * Vérifie que la copie est complète et que les dépendances ont été
     * correctement remappées vers les opérations de l'OF.
     *
     * @param  Collection<int, WorkOrderOperation>  $generated
     */
    protected function assertGenerationIntegrity(
        WorkOrder $workOrder,
        WorkshopRouting $routing,
        Collection $generated,
    ): void {
        $sourceOperations = $routing->operations()->with('dependencies')->get();

        if ($sourceOperations->count() !== $generated->count()) {
            $this->fail('routing', 'La génération des opérations est incomplète.');
        }

        $generatedBySource = $generated->keyBy('source_routing_operation_id');

        if ($generatedBySource->count() !== $sourceOperations->count()) {
            $this->fail('routing', 'La correspondance des opérations générées est incohérente.');
        }

        foreach ($sourceOperations as $sourceOperation) {
            $operation = $generatedBySource->get($sourceOperation->getKey());

            if (! $operation instanceof WorkOrderOperation) {
                $this->fail('routing', "L'opération source {$sourceOperation->code} n'a pas été copiée.");
            }

            $fieldMismatch = (string) $operation->code !== (string) $sourceOperation->code
                || (string) $operation->name !== (string) $sourceOperation->name
                || (string) ($operation->instructions ?? '') !== (string) ($sourceOperation->instructions ?? '')
                || (int) $operation->position !== (int) $sourceOperation->position
                || (int) ($operation->estimated_duration_minutes ?? -1) !== (int) ($sourceOperation->estimated_duration_minutes ?? -1)
                || (bool) $operation->is_mandatory !== (bool) $sourceOperation->is_mandatory;

            if ($fieldMismatch) {
                $this->fail('routing', "La copie de l'opération {$sourceOperation->code} est incohérente.");
            }

            $sourceDependencyIds = $sourceOperation->dependencies
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $generatedDependencySourceIds = $operation->dependencies()
                ->get()
                ->pluck('source_routing_operation_id')
                ->filter(fn (mixed $id): bool => $id !== null)
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($sourceDependencyIds !== $generatedDependencySourceIds) {
                $this->fail(
                    'routing',
                    "Les dépendances de l'opération {$sourceOperation->code} n'ont pas été correctement copiées.",
                );
            }

            if (! $workOrder->operations()->whereKey($operation->getKey())->exists()) {
                $this->fail('routing', 'Une opération générée n\'appartient pas à cet ordre de fabrication.');
            }
        }
    }

    /**
     * @param  Collection<int, WorkOrderOperation>  $operations
     */
    protected function assertOperationsCanBeReplaced(Collection $operations): void
    {
        $operationIds = $operations->pluck('id')->all();

        if (OperationTimeEntry::query()
            ->whereIn('work_order_operation_id', $operationIds)
            ->exists()) {
            $this->fail(
                'routing',
                'Des sessions de temps existent sur les opérations actuelles. La gamme ne peut pas être remplacée.',
            );
        }

        $hasExecutionState = $operations->contains(
            fn (WorkOrderOperation $operation): bool => $operation->started_at !== null
                || $operation->completed_at !== null
                || $operation->blocked_at !== null
                || ! in_array($operation->status, [WorkshopOperationStatus::Pending, WorkshopOperationStatus::Ready], true)
        );

        if ($hasExecutionState) {
            $this->fail(
                'routing',
                'Les opérations actuelles possèdent un historique d\'exécution. La gamme ne peut pas être remplacée.',
            );
        }

        $hasBusinessHistory = WorkOrderOperationAuditLog::query()
            ->whereIn('work_order_operation_id', $operationIds)
            ->whereNotIn('event', [WorkshopOperationAuditEvent::Created->value])
            ->exists();

        if ($hasBusinessHistory) {
            $this->fail(
                'routing',
                'Les opérations actuelles possèdent un historique métier. La gamme ne peut pas être remplacée.',
            );
        }
    }

    protected function assertOperationHasNoExecutionHistory(WorkOrderOperation $operation): void
    {
        if ($operation->timeEntries()->exists()) {
            $this->fail('operation', 'Cette opération possède des sessions de temps et ne peut pas être supprimée.');
        }

        if ($operation->started_at !== null
            || $operation->completed_at !== null
            || $operation->blocked_at !== null
            || ! in_array($operation->status, [WorkshopOperationStatus::Pending, WorkshopOperationStatus::Ready], true)) {
            $this->fail('operation', 'Cette opération possède un historique d\'exécution et ne peut pas être supprimée.');
        }

        if (WorkOrderOperationAuditLog::query()
            ->where('work_order_operation_id', $operation->getKey())
            ->whereNotIn('event', [WorkshopOperationAuditEvent::Created->value])
            ->exists()) {
            $this->fail('operation', 'Cette opération possède un historique métier et ne peut pas être supprimée.');
        }
    }

    protected function lockWorkOrderForOperation(
        WorkOrderOperation $operation,
        ?User $actor,
    ): WorkOrder {
        $workOrder = WorkOrder::query()->find($operation->work_order_id);

        if ($workOrder === null) {
            $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
        }

        $lockedWorkOrder = $this->workOrders->lockForMutation($workOrder);
        $this->assertCanManageRouting($lockedWorkOrder, $actor);

        return $lockedWorkOrder;
    }

    protected function lockOperation(
        WorkOrder $workOrder,
        WorkOrderOperation $operation,
    ): WorkOrderOperation {
        $locked = $workOrder->operations()
            ->whereKey($operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            $this->fail('operation', 'Cette opération n\'appartient pas à cet ordre de fabrication.');
        }

        return $locked;
    }

    protected function lockRouting(WorkshopRouting $routing): WorkshopRouting
    {
        $locked = WorkshopRouting::query()->lockForUpdate()->find($routing->getKey());

        if ($locked === null) {
            $this->fail('routing', 'La gamme sélectionnée est introuvable.');
        }

        return $locked;
    }

    protected function assertCanManageRouting(WorkOrder $workOrder, ?User $actor): void
    {
        if (! $workOrder->isRoutingEditable()) {
            $this->fail(
                'routing',
                "L'ordre de fabrication {$workOrder->number} est libéré ou clôturé : sa gamme et ses opérations ne sont plus modifiables.",
            );
        }

        if ($actor !== null && ! $actor->can('manageRouting', $workOrder)) {
            $this->fail('routing', 'Vous n\'êtes pas autorisé à modifier la gamme de cet ordre de fabrication.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validateOperationData(array $data, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return Validator::make($data, [
            'code' => [...$required, 'string', 'max:50'],
            'name' => [...$required, 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'estimated_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ])->validate();
    }

    protected function assertOperationCodeAvailable(
        WorkOrder $workOrder,
        string $code,
        ?int $ignoreOperationId = null,
    ): void {
        $query = $workOrder->operations()->where('code', $code);

        if ($ignoreOperationId !== null) {
            $query->whereKeyNot($ignoreOperationId);
        }

        if ($query->exists()) {
            $this->fail('code', "Le code d'opération « {$code} » est déjà utilisé dans cet OF.");
        }
    }

    protected function normalizeOperationCode(string $code): string
    {
        $normalized = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($code)) ?? '');

        if ($normalized === '') {
            $this->fail('code', 'Le code de l\'opération est obligatoire.');
        }

        return $normalized;
    }

    /**
     * @return array<int, array<int, int>>
     */
    protected function operationAdjacency(array $operationIds): array
    {
        $adjacency = array_fill_keys($operationIds, []);

        $rows = DB::table('work_order_operation_dependencies')
            ->whereIn('work_order_operation_id', $operationIds)
            ->get(['work_order_operation_id', 'depends_on_operation_id']);

        foreach ($rows as $row) {
            $source = (int) $row->work_order_operation_id;
            $target = (int) $row->depends_on_operation_id;

            if (array_key_exists($source, $adjacency)) {
                $adjacency[$source][] = $target;
            }
        }

        return $adjacency;
    }

    /**
     * @param  array<int, int>  $operationIds
     * @param  array<int, array<int, int>>  $adjacency
     * @return array<int, int>|null
     */
    protected function findOperationCycle(array $operationIds, array $adjacency): ?array
    {
        $state = array_fill_keys($operationIds, 0);
        $stack = [];

        $visit = function (int $node) use (&$visit, &$state, &$stack, $adjacency): ?array {
            $state[$node] = 1;
            $stack[] = $node;

            foreach ($adjacency[$node] ?? [] as $next) {
                if (! array_key_exists($next, $state)) {
                    continue;
                }

                if ($state[$next] === 1) {
                    $position = array_search($next, $stack, true);
                    $cycle = array_slice($stack, (int) $position);
                    $cycle[] = $next;

                    return $cycle;
                }

                if ($state[$next] === 0) {
                    $cycle = $visit($next);

                    if ($cycle !== null) {
                        return $cycle;
                    }
                }
            }

            $state[$node] = 2;
            array_pop($stack);

            return null;
        };

        foreach ($operationIds as $operationId) {
            if ($state[$operationId] !== 0) {
                continue;
            }

            $cycle = $visit($operationId);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    protected function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            $ids,
        )));
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
