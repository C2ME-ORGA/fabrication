<?php

namespace App\Services;

use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplateVersion;
use Illuminate\Validation\ValidationException;

/**
 * Validation métier d'une version de modèle avant activation.
 *
 * Les erreurs sont retournées par champ afin d'être affichées proprement
 * dans Filament, puis levées sous forme de ValidationException par le service
 * d'activation. Les règles sont rejouées sur tous les points : la validation
 * de saisie d'un point ne suffit pas à garantir la cohérence de l'ensemble.
 */
class QualityControlTemplateValidationService
{
    public function __construct(
        protected QualityControlPointService $points,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(QualityControlTemplateVersion $version): array
    {
        $errors = [];
        $points = $version->points()->get();

        if ($points->isEmpty()) {
            $errors['points'][] = 'Une version doit contenir au moins un point de contrôle avant d\'être activée.';
        }

        $positions = [];

        foreach ($points as $point) {
            $position = (int) $point->position;

            if ($position <= 0) {
                $errors['positions'][] = "La position du point « {$point->label} » doit être strictement positive.";
            } elseif (isset($positions[$position])) {
                $errors['positions'][] = "La position {$position} est utilisée par plusieurs points.";
            }

            $positions[$position] = true;

            foreach ($this->points->configurationErrors($point) as $message) {
                $errors['points'][] = $message;
            }
        }

        if ($points->isNotEmpty() && ! $points->contains(
            fn (QualityControlPoint $point): bool => (bool) $point->is_mandatory,
        )) {
            $errors['points'][] = 'Une version doit contenir au moins un point obligatoire.';
        }

        return $errors;
    }

    public function isValid(QualityControlTemplateVersion $version): bool
    {
        return $this->errors($version) === [];
    }

    /**
     * @throws ValidationException
     */
    public function assertValidForActivation(QualityControlTemplateVersion $version): void
    {
        $errors = $this->errors($version);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
