<?php

namespace App\Services;

use App\DTO\QualityEvaluation;
use App\Enums\QualityControlResultType;
use App\Models\WorkOrderQualityControlPoint;
use Illuminate\Validation\ValidationException;

/**
 * Évaluation automatique des valeurs d'un point de contrôle.
 *
 * Les tolérances sont inclusives. Une mesure sans aucune borne reste
 * informative (`is_conform = null`). Le type texte n'est jamais auto-évalué :
 * la conformité doit être qualifiée explicitement par l'opérateur.
 */
class QualityToleranceEvaluator
{
    public const VERSION = '1.0';

    /**
     * @param  array{value_boolean?: mixed, value_numeric?: mixed, value_text?: mixed, value_choice?: mixed, is_conform?: mixed}  $values
     */
    public function evaluate(WorkOrderQualityControlPoint $point, array $values): QualityEvaluation
    {
        return match ($point->result_type) {
            QualityControlResultType::Conformity => $this->evaluateConformity($values),
            QualityControlResultType::Numeric => $this->evaluateNumeric($point, $values),
            QualityControlResultType::Text => $this->evaluateText($values),
            QualityControlResultType::Choice => $this->evaluateChoice($point, $values),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function evaluateConformity(array $values): QualityEvaluation
    {
        $conform = (bool) ($values['value_boolean'] ?? false);

        return new QualityEvaluation($conform, [
            'type' => QualityControlResultType::Conformity->value,
            'rule' => 'boolean_value',
            'value' => $conform,
            'evaluator_version' => self::VERSION,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function evaluateNumeric(WorkOrderQualityControlPoint $point, array $values): QualityEvaluation
    {
        $value = $values['value_numeric'] ?? null;

        if ($value === null || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'value_numeric' => 'Une mesure numérique valide est obligatoire.',
            ]);
        }

        $min = $point->tolerance_min;
        $max = $point->tolerance_max;

        $detail = [
            'type' => QualityControlResultType::Numeric->value,
            'rule' => $min === null && $max === null ? 'informative' : 'inclusive_range',
            'value' => (string) $value,
            'tolerance_min' => $min,
            'tolerance_max' => $max,
            'unit' => $point->unit,
            'evaluator_version' => self::VERSION,
        ];

        if ($min === null && $max === null) {
            return new QualityEvaluation(null, $detail);
        }

        $conform = true;
        $deviation = null;

        if ($min !== null && $this->compare($value, $min) < 0) {
            $conform = false;
            $deviation = $this->subtract($value, $min);
        }

        if ($max !== null && $this->compare($value, $max) > 0) {
            $conform = false;
            $deviation ??= $this->subtract($value, $max);
        }

        $detail['deviation'] = $deviation;

        return new QualityEvaluation($conform, $detail);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function evaluateText(array $values): QualityEvaluation
    {
        $conform = $values['is_conform'] ?? null;

        if ($conform === null || ! is_bool($conform) && ! in_array($conform, [0, 1, '0', '1'], true)) {
            throw ValidationException::withMessages([
                'is_conform' => 'La conformité constatée doit être indiquée explicitement pour un contrôle texte.',
            ]);
        }

        return new QualityEvaluation((bool) $conform, [
            'type' => QualityControlResultType::Text->value,
            'rule' => 'explicit_user_conformity',
            'value' => (string) ($values['value_text'] ?? ''),
            'evaluator_version' => self::VERSION,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function evaluateChoice(WorkOrderQualityControlPoint $point, array $values): QualityEvaluation
    {
        $selected = $values['value_choice'] ?? null;

        if ($selected === null || $selected === '') {
            throw ValidationException::withMessages([
                'value_choice' => 'Une option doit être sélectionnée.',
            ]);
        }

        $option = collect($point->options ?? [])
            ->first(fn (array $candidate): bool => (string) ($candidate['value'] ?? '') === (string) $selected);

        if ($option === null) {
            throw ValidationException::withMessages([
                'value_choice' => 'L\'option sélectionnée n\'appartient pas à la liste du point de contrôle.',
            ]);
        }

        $conform = (bool) ($option['conformant'] ?? false);

        return new QualityEvaluation($conform, [
            'type' => QualityControlResultType::Choice->value,
            'rule' => 'option_conformant_flag',
            'value' => (string) $selected,
            'option_label' => $option['label'] ?? null,
            'evaluator_version' => self::VERSION,
        ]);
    }

    protected function compare(mixed $value, mixed $bound): int
    {
        if (function_exists('bccomp')) {
            return bccomp((string) $value, (string) $bound, 4);
        }

        return (float) $value <=> (float) $bound;
    }

    protected function subtract(mixed $value, mixed $bound): string
    {
        if (function_exists('bcsub')) {
            return bcsub((string) $value, (string) $bound, 4);
        }

        return number_format((float) $value - (float) $bound, 4, '.', '');
    }
}
