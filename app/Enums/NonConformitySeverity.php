<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Gravité d'une non-conformité.
 *
 * Les conséquences de chaque gravité sont figées à la création de la NC dans
 * `severity_policy_snapshot`.
 */
enum NonConformitySeverity: string implements HasColor, HasLabel
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return match ($this) {
            self::Minor => 'Mineure',
            self::Major => 'Majeure',
            self::Critical => 'Critique',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Minor => 'gray',
            self::Major => 'warning',
            self::Critical => 'danger',
        };
    }

    public function requiresQualityManager(): bool
    {
        return in_array($this, [self::Major, self::Critical], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $severity): array => $options + [$severity->value => $severity->getLabel()],
            [],
        );
    }
}
