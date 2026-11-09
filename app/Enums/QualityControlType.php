<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nature métier d'un modèle de contrôle qualité.
 *
 * Le type est porté par le modèle et recopié dans le plan qualité de chaque
 * OF au moment de la génération : modifier le référentiel ensuite ne change
 * jamais un contrôle déjà généré.
 */
enum QualityControlType: string implements HasColor, HasLabel
{
    case Dimensional = 'dimensional';
    case Visual = 'visual';
    case Welding = 'welding';
    case PreparationFinishing = 'preparation_finishing';
    case Final = 'final';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dimensional => 'Contrôle dimensionnel',
            self::Visual => 'Contrôle visuel',
            self::Welding => 'Contrôle de soudure',
            self::PreparationFinishing => 'Préparation et finition',
            self::Final => 'Contrôle final',
            self::Other => 'Autre contrôle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Dimensional => 'info',
            self::Visual => 'gray',
            self::Welding => 'warning',
            self::PreparationFinishing => 'primary',
            self::Final => 'success',
            self::Other => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $type): array => $options + [$type->value => $type->getLabel()],
            [],
        );
    }
}
