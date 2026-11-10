<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Périmètre d'une dérogation qualité.
 *
 * `work_order` n'est pas utilisable pour créer une dérogation globale : une
 * cible précise est toujours obligatoire.
 */
enum QualityDeviationScope: string implements HasLabel
{
    case Control = 'control';
    case ControlPoint = 'control_point';
    case NonConformity = 'non_conformity';
    case WorkOrder = 'work_order';

    public function getLabel(): string
    {
        return match ($this) {
            self::Control => 'Contrôle qualité',
            self::ControlPoint => 'Point de contrôle',
            self::NonConformity => 'Non-conformité',
            self::WorkOrder => 'Ordre de fabrication (non autorisé)',
        };
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
