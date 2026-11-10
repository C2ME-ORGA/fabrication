<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Résultat d'une vérification de non-conformité.
 */
enum NonConformityVerificationResult: string implements HasColor, HasLabel
{
    case Conforming = 'conforming';
    case NonConforming = 'non_conforming';

    public function getLabel(): string
    {
        return match ($this) {
            self::Conforming => 'Conforme',
            self::NonConforming => 'Non conforme',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Conforming => 'success',
            self::NonConforming => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $result): array => $options + [$result->value => $result->getLabel()],
            [],
        );
    }
}
