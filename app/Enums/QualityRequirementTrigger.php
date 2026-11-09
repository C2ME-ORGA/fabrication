<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Moment auquel une exigence qualité devient exigible au cours de l'OF.
 */
enum QualityRequirementTrigger: string implements HasLabel
{
    case OnStart = 'on_start';
    case OnCompletion = 'on_completion';
    case Final = 'final';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnStart => 'Avant démarrage',
            self::OnCompletion => 'À la terminaison',
            self::Final => 'Contrôle final',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $trigger): array => $options + [$trigger->value => $trigger->getLabel()],
            [],
        );
    }
}
