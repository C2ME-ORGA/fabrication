<?php

namespace App\Services;

use App\Models\WorkshopRouting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée unique des mutations d'une gamme atelier.
 *
 * Une gamme est créée inactive : elle doit être complétée puis activée via
 * une validation métier complète. La désactivation reste toujours possible,
 * y compris pour une gamme utilisée historiquement.
 */
class WorkshopRoutingService
{
    public function __construct(
        protected WorkshopRoutingValidationService $validation,
        protected WorkshopRoutingOperationService $operations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WorkshopRouting
    {
        $validated = $this->validate($data);
        $requestedActive = (bool) ($validated['is_active'] ?? false);

        try {
            return DB::transaction(function () use ($validated, $requestedActive): WorkshopRouting {
                $routing = new WorkshopRouting;
                $routing->fill(Arr::only($validated, ['code', 'name', 'description']));
                $routing->is_active = false;
                $routing->save();

                foreach ($validated['operations'] ?? [] as $operationData) {
                    $this->operations->create($routing, $operationData);
                }

                if ($requestedActive) {
                    $this->activate($routing);
                }

                return $routing->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('code', 'Ce code de gamme est déjà utilisé.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkshopRouting $routing, array $data): WorkshopRouting
    {
        $validated = $this->validate($data, partial: true, ignoreId: (int) $routing->getKey());

        try {
            return DB::transaction(function () use ($routing, $validated): WorkshopRouting {
                $locked = $this->lock($routing);

                $locked->fill(Arr::only($validated, ['code', 'name', 'description']));
                $locked->save();

                if (array_key_exists('is_active', $validated)) {
                    if ($validated['is_active']) {
                        $this->activate($locked);
                    } else {
                        $this->deactivate($locked);
                    }
                }

                return $locked->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('code', 'Ce code de gamme est déjà utilisé.');
        }
    }

    public function activate(WorkshopRouting $routing): WorkshopRouting
    {
        return DB::transaction(function () use ($routing): WorkshopRouting {
            $locked = $this->lock($routing);

            if ($locked->isActive()) {
                return $locked->refresh();
            }

            // La cohérence est vérifiée avant toute correction de numérotation :
            // une gamme dont les positions ou le graphe sont invalides ne doit
            // pas être activée, même si les positions pouvaient être normalisées.
            $this->validation->assertValidForActivation($locked);
            $this->operations->normalizePositions($locked);

            $locked->is_active = true;
            $locked->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    public function deactivate(WorkshopRouting $routing): WorkshopRouting
    {
        return DB::transaction(function () use ($routing): WorkshopRouting {
            $locked = $this->lock($routing);

            if (! $locked->isActive()) {
                return $locked->refresh();
            }

            $locked->is_active = false;
            $locked->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Suppression physique réservée aux gammes jamais utilisées.
     */
    public function delete(WorkshopRouting $routing): void
    {
        DB::transaction(function () use ($routing): void {
            $locked = $this->lock($routing);

            if ($locked->isUsedByWorkOrders()) {
                $this->fail(
                    'routing',
                    'Cette gamme est référencée par un ordre de fabrication et ne peut pas être supprimée. Désactivez-la.',
                );
            }

            if ($locked->hasGeneratedOperations()) {
                $this->fail(
                    'routing',
                    'Cette gamme a servi à générer des opérations d\'OF et ne peut pas être supprimée. Désactivez-la.',
                );
            }

            $locked->delete();
        }, attempts: 3);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function activationErrors(WorkshopRouting $routing): array
    {
        return $this->validation->errors($routing);
    }

    public function isValidForActivation(WorkshopRouting $routing): bool
    {
        return $this->validation->isValid($routing);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false, ?int $ignoreId = null): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        $uniqueCode = Rule::unique('workshop_routings', 'code');

        if ($ignoreId !== null) {
            $uniqueCode->ignore($ignoreId);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->normalizeCode((string) $data['code']);
        }

        return Validator::make($data, [
            'code' => [...$required, 'string', 'max:50', $uniqueCode],
            'name' => [...$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'operations' => ['sometimes', 'array'],
        ])->validate();
    }

    protected function normalizeCode(string $code): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($code)) ?? '';
        $normalized = mb_strtoupper($normalized);

        if ($normalized === '') {
            $this->fail('code', 'Le code de la gamme est obligatoire.');
        }

        return $normalized;
    }

    protected function lock(WorkshopRouting $routing): WorkshopRouting
    {
        $locked = WorkshopRouting::query()->lockForUpdate()->find($routing->getKey());

        if ($locked === null) {
            $this->fail('routing', 'Cette gamme n\'existe plus.');
        }

        return $locked;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
