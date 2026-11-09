<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Origine d'une session de temps opérateur.
 *
 * `timer` est une session démarrée/arrêtée par l'opérateur, `manual` une
 * saisie effectuée par un responsable atelier ou un administrateur, et
 * `adjustment` une correction d'une session existante (l'original n'est
 * jamais écrasé silencieusement).
 */
enum OperationTimeEntryType: string implements HasColor, HasLabel
{
    case Timer = 'timer';
    case Manual = 'manual';
    case Adjustment = 'adjustment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Timer => 'Chronomètre',
            self::Manual => 'Saisie manuelle',
            self::Adjustment => 'Ajustement',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Timer => 'info',
            self::Manual => 'primary',
            self::Adjustment => 'warning',
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
