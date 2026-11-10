<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'une version de modèle de contrôle.
 *
 * Une seule version `active` par modèle (garantie par index unique partiel
 * émulé par colonne générée). Les versions `retired` restent consultables
 * pour l'historique ; les versions `draft` sont les seules modifiables.
 */
enum QualityControlTemplateVersionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Active => 'Active',
            self::Retired => 'Retirée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Retired => 'warning',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isRetired(): bool
    {
        return $this === self::Retired;
    }

    /**
     * Seule une version brouillon peut être modifiée.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $status): array => $options + [$status->value => $status->getLabel()],
            [],
        );
    }
}
