<?php

namespace App\Services;

use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validation métier centralisée d'une gamme atelier avant activation.
 *
 * Les erreurs sont retournées par champ afin de pouvoir être affichées
 * proprement dans Filament, et levées sous forme de ValidationException par
 * les services qui doivent refuser l'opération.
 */
class WorkshopRoutingValidationService
{
    public function __construct(
        protected OperationDependencyService $dependencies,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(WorkshopRouting $routing): array
    {
        /** @var Collection<int, WorkshopRoutingOperation> $operations */
        $operations = $routing->operations()->get();
        $errors = [];

        if ($operations->isEmpty()) {
            $errors['operations'][] = 'Une gamme doit contenir au moins une opération avant d\'être activée.';
        }

        $codes = [];
        $positions = [];

        foreach ($operations as $operation) {
            $code = mb_strtoupper(trim((string) $operation->code));

            if ($code === '') {
                $errors['code'][] = 'Chaque opération doit posséder un code.';
            } elseif (isset($codes[$code])) {
                $errors['code'][] = "Le code d'opération « {$code} » est utilisé plusieurs fois dans cette gamme.";
            }

            $codes[$code] = true;

            $position = (int) $operation->position;

            if ($position <= 0) {
                $errors['operations'][] = "La position de l'opération « {$operation->code} » doit être strictement positive.";
            } elseif (isset($positions[$position])) {
                $errors['operations'][] = "La position {$position} est utilisée par plusieurs opérations.";
            }

            $positions[$position] = true;

            if ($operation->estimated_duration_minutes !== null && $operation->estimated_duration_minutes < 0) {
                $errors['operations'][] = "La durée estimée de l'opération « {$operation->code} » ne peut pas être négative.";
            }
        }

        if ($operations->isNotEmpty() && ! $operations->contains(fn (WorkshopRoutingOperation $operation): bool => $operation->is_mandatory)) {
            $errors['operations'][] = 'Une gamme doit contenir au moins une opération obligatoire.';
        }

        $graphErrors = $this->dependencies->graphErrors($routing);

        if ($graphErrors !== []) {
            $errors['dependencies'] = $graphErrors;
        }

        return $errors;
    }

    public function isValid(WorkshopRouting $routing): bool
    {
        return $this->errors($routing) === [];
    }

    /**
     * @throws ValidationException
     */
    public function assertValidForActivation(WorkshopRouting $routing): void
    {
        $errors = $this->errors($routing);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Une gamme doit être cohérente avant d'être affectée à un OF.
     *
     * @throws ValidationException
     */
    public function assertValidForAssignment(WorkshopRouting $routing): void
    {
        $this->assertValidForActivation($routing);
    }
}
