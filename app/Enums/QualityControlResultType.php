<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Type de résultat attendu pour un point de contrôle.
 *
 * `conformity` : conforme / non conforme.
 * `numeric`    : mesure numérique évaluée automatiquement par rapport aux
 *                tolérances configurées.
 * `text`       : texte libre, avec qualification de conformité explicite.
 * `choice`     : liste de choix, chaque option pouvant être conforme ou non.
 */
enum QualityControlResultType: string implements HasLabel
{
    case Conformity = 'conformity';
    case Numeric = 'numeric';
    case Text = 'text';
    case Choice = 'choice';

    public function getLabel(): string
    {
        return match ($this) {
            self::Conformity => 'Conforme / Non conforme',
            self::Numeric => 'Mesure numérique',
            self::Text => 'Texte libre',
            self::Choice => 'Liste de choix',
        };
    }

    public function requiresUnit(): bool
    {
        return $this === self::Numeric;
    }

    public function usesTolerances(): bool
    {
        return $this === self::Numeric;
    }

    public function usesOptions(): bool
    {
        return $this === self::Choice;
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
