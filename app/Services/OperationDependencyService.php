<?php

namespace App\Services;

use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\User;
use App\Models\WorkOrderOperation;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestion et validation du graphe de dépendances.
 *
 * Le service couvre deux usages :
 * - le graphe modèle d'une gamme atelier (ajout, suppression, cycles) ;
 * - le graphe d'exécution d'un OF (recalcul pending/ready et propagation).
 *
 * Une arête `A → B` signifie « A dépend de B » : B doit être terminée avant
 * que A puisse démarrer. Les cycles directs et indirects sont refusés avant
 * toute écriture. Toutes les mutations verrouillent la gamme puis les
 * opérations dans un ordre déterministe.
 */
class OperationDependencyService
{
    public function __construct(
        protected WorkOrderOperationAuditService $audit,
    ) {}

    /**
     * Toutes les dépendances d'une opération d'OF sont-elles terminées ?
     */
    public function dependenciesSatisfied(WorkOrderOperation $operation): bool
    {
        return $operation->dependenciesSatisfied();
    }

    /**
     * Recalcule l'état d'une opération d'OF.
     *
     * Seule une opération `pending` peut devenir `ready`. Les opérations en
     * cours, en pause, bloquées, terminées ou annulées ne sont jamais
     * rétrogradées automatiquement.
     */
    public function recalculate(
        WorkOrderOperation $operation,
        ?User $actor = null,
        ?WorkOrderOperation $triggeredBy = null,
    ): bool {
        if ($operation->status !== WorkshopOperationStatus::Pending) {
            return false;
        }

        if (! $this->dependenciesSatisfied($operation)) {
            return false;
        }

        $operation->forceFill(['status' => WorkshopOperationStatus::Ready])->save();

        $this->audit->record(
            $operation,
            $operation->workOrder,
            WorkshopOperationAuditEvent::Ready,
            $actor,
            WorkshopOperationStatus::Pending->value,
            WorkshopOperationStatus::Ready->value,
            null,
            [
                'dependencies_count' => $operation->dependencies()->count(),
                'triggered_by_operation_id' => $triggeredBy?->getKey(),
            ],
        );

        return true;
    }

    /**
     * Propage le recalcul aux opérations qui dépendent de celle qui vient
     * d'être terminée.
     *
     * @return Collection<int, WorkOrderOperation>
     */
    public function propagateAfterCompletion(WorkOrderOperation $operation, ?User $actor = null): Collection
    {
        $changed = new Collection;

        $dependents = $operation->dependents()
            ->orderBy('work_order_operations.id')
            ->lockForUpdate()
            ->get();

        foreach ($dependents as $dependent) {
            if ($this->recalculate($dependent, $actor, $operation)) {
                $changed->push($dependent->refresh());
            }
        }

        return $changed;
    }

    public function add(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        WorkshopRoutingOperation $dependsOn,
    ): void {
        $this->assertNotSelf($operation, $dependsOn);

        DB::transaction(function () use ($routing, $operation, $dependsOn): void {
            $lockedRouting = $this->lockRouting($routing);
            [$lockedOperation, $lockedDependsOn] = $this->lockPair(
                $lockedRouting,
                $operation,
                $dependsOn,
            );

            if ($lockedOperation->dependencies()->whereKey($lockedDependsOn->getKey())->exists()) {
                $this->fail('dependencies', 'Cette dépendance existe déjà pour cette opération.');
            }

            $cycle = $this->findCycle($lockedRouting, [
                $lockedOperation->getKey(),
                $lockedDependsOn->getKey(),
            ]);

            if ($cycle !== null) {
                $this->fail('dependencies', $this->cycleMessage($cycle, $lockedRouting));
            }

            $lockedOperation->dependencies()->attach($lockedDependsOn->getKey());
        }, attempts: 3);
    }

    public function remove(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        WorkshopRoutingOperation $dependsOn,
    ): void {
        DB::transaction(function () use ($routing, $operation, $dependsOn): void {
            $lockedRouting = $this->lockRouting($routing);
            [$lockedOperation, $lockedDependsOn] = $this->lockPair(
                $lockedRouting,
                $operation,
                $dependsOn,
            );

            if (! $lockedOperation->dependencies()->whereKey($lockedDependsOn->getKey())->exists()) {
                $this->fail('dependencies', 'Cette dépendance n\'existe pas.');
            }

            $lockedOperation->dependencies()->detach($lockedDependsOn->getKey());
        }, attempts: 3);
    }

    /**
     * Remplace l'ensemble des dépendances d'une opération.
     *
     * @param  array<int, int|string>  $dependencyIds
     */
    public function syncDependencies(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        array $dependencyIds,
    ): void {
        $dependencyIds = $this->normalizeIds($dependencyIds);

        if (in_array((int) $operation->getKey(), $dependencyIds, true)) {
            $this->fail('dependencies', 'Une opération ne peut pas dépendre d\'elle-même.');
        }

        DB::transaction(function () use ($routing, $operation, $dependencyIds): void {
            $lockedRouting = $this->lockRouting($routing);
            [$lockedOperation, $lockedDependencies] = $this->lockOperationAndDependencies(
                $lockedRouting,
                $operation,
                $dependencyIds,
            );

            $adjacency = $this->adjacency($lockedRouting);
            $adjacency[(int) $lockedOperation->getKey()] = $dependencyIds;

            $cycle = $this->findCycleIds(
                $this->operationIds($lockedRouting),
                $adjacency,
            );

            if ($cycle !== null) {
                $this->fail('dependencies', $this->cycleMessage($cycle, $lockedRouting));
            }

            $lockedOperation->dependencies()->sync($dependencyIds);
        }, attempts: 3);
    }

    /**
     * Vérifie toutes les règles du graphe sans écrire.
     *
     * @return array<int, string>
     */
    public function graphErrors(WorkshopRouting $routing): array
    {
        $operationIds = $this->operationIds($routing);
        $codes = $routing->operations()->pluck('code', 'id')->all();
        $errors = [];

        $rows = DB::table('workshop_routing_operation_dependencies')
            ->whereIn('workshop_routing_operation_id', $operationIds)
            ->get(['workshop_routing_operation_id', 'depends_on_operation_id']);

        $seen = [];

        foreach ($rows as $row) {
            $sourceId = (int) $row->workshop_routing_operation_id;
            $targetId = (int) $row->depends_on_operation_id;
            $sourceCode = $codes[$sourceId] ?? "#{$sourceId}";

            if ($sourceId === $targetId) {
                $errors[] = "L'opération « {$sourceCode} » ne peut pas dépendre d'elle-même.";

                continue;
            }

            if (! in_array($targetId, $operationIds, true)) {
                $errors[] = "L'opération « {$sourceCode} » dépend d'une opération qui n'appartient pas à cette gamme.";

                continue;
            }

            $key = $sourceId.'-'.$targetId;
            if (isset($seen[$key])) {
                $targetCode = $codes[$targetId] ?? "#{$targetId}";
                $errors[] = "La dépendance « {$sourceCode} » → « {$targetCode} » est enregistrée plusieurs fois.";
            }
            $seen[$key] = true;
        }

        $cycle = $this->findCycle($routing);
        if ($cycle !== null) {
            $errors[] = $this->cycleMessage($cycle, $routing);
        }

        return array_values(array_unique($errors));
    }

    /**
     * Détecte un cycle après ajout éventuel d'une arête.
     *
     * @param  array{0: int|string, 1: int|string}|null  $extraEdge
     * @return array<int, int>|null Identifiants du cycle, premier = dernier.
     */
    public function findCycle(WorkshopRouting $routing, ?array $extraEdge = null): ?array
    {
        $operationIds = $this->operationIds($routing);
        $adjacency = $this->adjacency($routing);

        if ($extraEdge !== null) {
            $source = (int) $extraEdge[0];
            $target = (int) $extraEdge[1];
            $adjacency[$source] ??= [];
            $adjacency[$source][] = $target;
        }

        return $this->findCycleIds($operationIds, $adjacency);
    }

    public function wouldCreateCycle(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        WorkshopRoutingOperation $dependsOn,
    ): bool {
        return $this->findCycle($routing, [
            $operation->getKey(),
            $dependsOn->getKey(),
        ]) !== null;
    }

    /**
     * @return array<int, array<int, int>>
     */
    protected function adjacency(WorkshopRouting $routing): array
    {
        $operationIds = $this->operationIds($routing);
        $adjacency = array_fill_keys($operationIds, []);

        $rows = DB::table('workshop_routing_operation_dependencies')
            ->whereIn('workshop_routing_operation_id', $operationIds)
            ->get(['workshop_routing_operation_id', 'depends_on_operation_id']);

        foreach ($rows as $row) {
            $source = (int) $row->workshop_routing_operation_id;
            $target = (int) $row->depends_on_operation_id;

            if (in_array($target, $operationIds, true)) {
                $adjacency[$source][] = $target;
            }
        }

        return $adjacency;
    }

    /**
     * Parcours en profondeur avec détection des nœuds en cours de visite.
     *
     * @param  array<int, int>  $operationIds
     * @param  array<int, array<int, int>>  $adjacency
     * @return array<int, int>|null
     */
    protected function findCycleIds(array $operationIds, array $adjacency): ?array
    {
        /** @var array<int, int> $state 0 = non visité, 1 = en cours, 2 = terminé */
        $state = array_fill_keys($operationIds, 0);
        /** @var array<int, int> $stack */
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
     * @param  array<int, int>  $cycleIds
     */
    protected function cycleMessage(array $cycleIds, ?WorkshopRouting $routing = null): string
    {
        if ($routing !== null) {
            $codes = $routing->operations()->pluck('code', 'id')->all();
            $labels = array_map(
                fn (int $id): string => $codes[$id] ?? "#{$id}",
                $cycleIds,
            );
        } else {
            $labels = array_map(fn (int $id): string => "#{$id}", $cycleIds);
        }

        return 'Dépendance cyclique détectée : '.implode(' → ', $labels).'.';
    }

    /**
     * @return array<int, int>
     */
    protected function operationIds(WorkshopRouting $routing): array
    {
        return $routing->operations()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
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

    protected function lockRouting(WorkshopRouting $routing): WorkshopRouting
    {
        $locked = WorkshopRouting::query()->lockForUpdate()->find($routing->getKey());

        if ($locked === null) {
            $this->fail('routing', 'Cette gamme n\'existe plus.');
        }

        return $locked;
    }

    /**
     * @return array{0: WorkshopRoutingOperation, 1: WorkshopRoutingOperation}
     */
    protected function lockPair(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        WorkshopRoutingOperation $dependsOn,
    ): array {
        $operations = $routing->operations()
            ->whereKey([$operation->getKey(), $dependsOn->getKey()])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($operations->count() !== 2) {
            $this->fail(
                'dependencies',
                'Les deux opérations doivent appartenir à la même gamme.',
            );
        }

        return [
            $operations->firstWhere('id', $operation->getKey()),
            $operations->firstWhere('id', $dependsOn->getKey()),
        ];
    }

    /**
     * @param  array<int, int>  $dependencyIds
     * @return array{0: WorkshopRoutingOperation, 1: array<int, int>}
     */
    protected function lockOperationAndDependencies(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        array $dependencyIds,
    ): array {
        $ids = array_values(array_unique([...$dependencyIds, (int) $operation->getKey()]));

        $operations = $routing->operations()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($operations->count() !== count($ids)) {
            $this->fail(
                'dependencies',
                'Toutes les opérations sélectionnées doivent appartenir à cette gamme.',
            );
        }

        $lockedOperation = $operations->firstWhere('id', $operation->getKey());

        if ($lockedOperation === null) {
            $this->fail('dependencies', 'Cette opération n\'appartient pas à cette gamme.');
        }

        return [$lockedOperation, $dependencyIds];
    }

    protected function assertNotSelf(
        WorkshopRoutingOperation $operation,
        WorkshopRoutingOperation $dependsOn,
    ): void {
        if ((int) $operation->getKey() === (int) $dependsOn->getKey()) {
            $this->fail('dependencies', 'Une opération ne peut pas dépendre d\'elle-même.');
        }
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
