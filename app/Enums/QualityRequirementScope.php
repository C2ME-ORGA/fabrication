<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Portée d'une exigence qualité portée par une opération de gamme.
 */
enum QualityRequirementScope: string implements HasLabel
{
    case Operation = 'operation';
    case Item = 'item';
    case WorkOrder = 'work_order';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operation => 'Opération',
            self::Item => 'Repère / pièce',
            self::WorkOrder => 'Ordre de fabrication',
        };
    }

    /**
     * L'échantillonnage par fréquence n'a de sens que sur des repères : les
     * portées opération et OF ne disposent d'aucune population à échantillonner.
     */
    public function allowsFrequencySampling(): bool
    {
        return $this === self::Item;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $scope): array => $options + [$scope->value => $scope->getLabel()],
            [],
        );
    }
}
