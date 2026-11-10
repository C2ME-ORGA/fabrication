<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mode d'échantillonnage d'un point de contrôle.
 *
 * `all`      : toutes les pièces applicables (nombre de repères de l'OF).
 * `every_n`  : une pièce sur N (sampling_every), uniquement pour la portée item.
 * `fixed`    : un nombre déterminé de pièces (required_results).
 */
enum QualityControlSamplingMode: string implements HasLabel
{
    case All = 'all';
    case EveryN = 'every_n';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Toutes les pièces',
            self::EveryN => 'Une pièce sur N',
            self::Fixed => 'Nombre déterminé',
        };
    }

    public function requiresEvery(): bool
    {
        return $this === self::EveryN;
    }

    public function usesRequiredResultsAsContribution(): bool
    {
        return $this === self::Fixed;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $mode): array => $options + [$mode->value => $mode->getLabel()],
            [],
        );
    }
}
