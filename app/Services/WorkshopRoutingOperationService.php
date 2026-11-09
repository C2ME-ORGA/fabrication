<?php

namespace App\Services;

use App\Models\WorkOrderOperation;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des opérations d'une gamme atelier.
 *
 * Toutes les mutations verrouillent la gamme puis les opérations concernées.
 * Les positions sont normalisées par pas de 10 afin de rester cohérentes après
 * un ajout, une suppression ou une réorganisation.
 */
class WorkshopRoutingOperationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(WorkshopRouting $routing, array $data): WorkshopRoutingOperation
    {
        $validated = $this->validate($data);
        $validated['code'] = $this->normalizeCode($validated['code']);

        return DB::transaction(function () use ($routing, $validated): WorkshopRoutingOperation {
            $lockedRouting = $this->lockRouting($routing);
            $this->assertCodeAvailable($lockedRouting, $validated['code']);

            $position = array_key_exists('position', $validated)
                ? (int) $validated['position']
                : $this->nextPosition($lockedRouting);

            $operation = $lockedRouting->operations()->create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'instructions' => $validated['instructions'] ?? null,
                'position' => $position,
                'estimated_duration_minutes' => $validated['estimated_duration_minutes'] ?? null,
                'is_mandatory' => $validated['is_mandatory'] ?? true,
            ]);

            $this->normalizePositions($lockedRouting);

            return $operation->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkshopRoutingOperation $operation, array $data): WorkshopRoutingOperation
    {
        $validated = $this->validate($data, partial: true);

        if (array_key_exists('code', $validated)) {
            $validated['code'] = $this->normalizeCode($validated['code']);
        }

        return DB::transaction(function () use ($operation, $validated): WorkshopRoutingOperation {
            $lockedRouting = $this->lockRoutingForOperation($operation);
            $lockedOperation = $this->lockOperation($lockedRouting, $operation);

            if (array_key_exists('code', $validated)) {
                $this->assertCodeAvailable(
                    $lockedRouting,
                    $validated['code'],
                    (int) $lockedOperation->getKey(),
                );
            }

            $lockedOperation->fill($validated);
            $lockedOperation->save();

            if (array_key_exists('position', $validated)) {
                $this->normalizePositions($lockedRouting);
            }

            return $lockedOperation->refresh();
        }, attempts: 3);
    }

    /**
     * Supprime une opération uniquement si elle n'est référencée ni par une
     * dépendance de gamme, ni par une opération d'OF déjà générée.
     */
    public function delete(WorkshopRoutingOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $lockedRouting = $this->lockRoutingForOperation($operation);
            $lockedOperation = $this->lockOperation($lockedRouting, $operation);

            if ($lockedOperation->dependencies()->exists() || $lockedOperation->dependents()->exists()) {
                $this->fail(
                    'operation',
                    'Cette opération possède des dépendances. Supprimez-les explicitement avant de supprimer l\'opération.',
                );
            }

            if (WorkOrderOperation::query()
                ->where('source_routing_operation_id', $lockedOperation->getKey())
                ->exists()) {
                $this->fail(
                    'operation',
                    'Cette opération a déjà été utilisée pour générer une opération d\'OF et ne peut pas être supprimée.',
                );
            }

            $lockedOperation->delete();

            $this->normalizePositions($lockedRouting);
        }, attempts: 3);
    }

    /**
     * @param  array<int, int|string>  $orderedOperationIds
     */
    public function reorder(WorkshopRouting $routing, array $orderedOperationIds): void
    {
        $orderedOperationIds = array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            $orderedOperationIds,
        )));

        DB::transaction(function () use ($routing, $orderedOperationIds): void {
            $lockedRouting = $this->lockRouting($routing);
            $operations = $lockedRouting->operations()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existingIds = $operations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $expected = $existingIds;
            sort($expected);
            $provided = $orderedOperationIds;
            sort($provided);

            if ($expected !== $provided) {
                $this->fail(
                    'operations',
                    'La liste de réorganisation doit contenir exactement toutes les opérations de la gamme.',
                );
            }

            $positions = [];

            foreach ($orderedOperationIds as $index => $operationId) {
                $positions[$operationId] = ($index + 1) * 10;
            }

            foreach ($operations as $operation) {
                $operation->position = $positions[(int) $operation->getKey()];
                $operation->save();
            }
        }, attempts: 3);
    }

    public function moveUp(WorkshopRoutingOperation $operation): void
    {
        $this->move($operation, -1);
    }

    public function moveDown(WorkshopRoutingOperation $operation): void
    {
        $this->move($operation, 1);
    }

    /**
     * Recalcule les positions 10, 20, 30… selon l'ordre courant.
     */
    public function normalizePositions(WorkshopRouting $routing): void
    {
        DB::transaction(function () use ($routing): void {
            $lockedRouting = $this->lockRouting($routing);
            $operations = $lockedRouting->operations()
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($operations as $index => $operation) {
                $position = ($index + 1) * 10;

                if ((int) $operation->position !== $position) {
                    $operation->position = $position;
                    $operation->save();
                }
            }
        }, attempts: 3);
    }

    public function nextPosition(WorkshopRouting $routing): int
    {
        return ((int) $routing->operations()->max('position')) + 10;
    }

    protected function move(WorkshopRoutingOperation $operation, int $offset): void
    {
        DB::transaction(function () use ($operation, $offset): void {
            $lockedRouting = $this->lockRoutingForOperation($operation);
            $operations = $lockedRouting->operations()
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $currentIndex = $operations->search(
                fn (WorkshopRoutingOperation $candidate): bool => (int) $candidate->getKey() === (int) $operation->getKey(),
            );

            if ($currentIndex === false) {
                $this->fail('operation', 'Cette opération n\'appartient pas à cette gamme.');
            }

            $targetIndex = $currentIndex + $offset;

            if ($targetIndex < 0 || $targetIndex >= $operations->count()) {
                return;
            }

            $ordered = $operations->all();
            [$ordered[$currentIndex], $ordered[$targetIndex]] = [$ordered[$targetIndex], $ordered[$currentIndex]];

            foreach ($ordered as $index => $candidate) {
                $candidate->position = ($index + 1) * 10;
                $candidate->save();
            }
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        $validated = Validator::make($data, [
            'code' => [...$required, 'string', 'max:50'],
            'name' => [...$required, 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'estimated_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ])->validate();

        if (array_key_exists('code', $validated)) {
            $validated['code'] = $this->normalizeCode($validated['code']);
        }

        return $validated;
    }

    protected function normalizeCode(string $code): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($code)) ?? '';
        $normalized = mb_strtoupper($normalized);

        if ($normalized === '') {
            $this->fail('code', 'Le code de l\'opération est obligatoire.');
        }

        return $normalized;
    }

    protected function assertCodeAvailable(
        WorkshopRouting $routing,
        string $code,
        ?int $ignoreOperationId = null,
    ): void {
        $query = $routing->operations()->where('code', $code);

        if ($ignoreOperationId !== null) {
            $query->whereKeyNot($ignoreOperationId);
        }

        if ($query->exists()) {
            $this->fail(
                'code',
                "Le code d'opération « {$code} » est déjà utilisé dans cette gamme.",
            );
        }
    }

    protected function lockRouting(WorkshopRouting $routing): WorkshopRouting
    {
        $locked = WorkshopRouting::query()->lockForUpdate()->find($routing->getKey());

        if ($locked === null) {
            $this->fail('routing', 'Cette gamme n\'existe plus.');
        }

        return $locked;
    }

    protected function lockRoutingForOperation(WorkshopRoutingOperation $operation): WorkshopRouting
    {
        $routing = $operation->routing()->first();

        if ($routing === null) {
            $this->fail('routing', 'Cette gamme n\'existe plus.');
        }

        return $this->lockRouting($routing);
    }

    protected function lockOperation(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
    ): WorkshopRoutingOperation {
        $locked = $routing->operations()
            ->whereKey($operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            $this->fail('operation', 'Cette opération n\'appartient pas à cette gamme.');
        }

        return $locked;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
