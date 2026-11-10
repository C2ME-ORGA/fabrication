<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Décision de validation d'un résultat de contrôle.
 *
 * Les décisions sont append-only : une validation supplémentaire ne remplace
 * jamais une décision précédente, elle s'y ajoute (dans la limite du nombre
 * de validations requis par la criticité du point).
 */
enum QualityResultDecision: string implements HasColor, HasLabel
{
    case Validated = 'validated';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Validated => 'Validé',
            self::Rejected => 'Rejeté',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Validated => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $decision): array => $options + [$decision->value => $decision->getLabel()],
            [],
        );
    }
}
