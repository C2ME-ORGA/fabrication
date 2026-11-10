<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Type de vérification d'une non-conformité.
 */
enum NonConformityVerificationType: string implements HasLabel
{
    case CounterControl = 'counter_control';
    case Effectiveness = 'effectiveness';
    case ClosureReview = 'closure_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::CounterControl => 'Contre-contrôle',
            self::Effectiveness => 'Vérification d\'efficacité',
            self::ClosureReview => 'Revue de clôture',
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
