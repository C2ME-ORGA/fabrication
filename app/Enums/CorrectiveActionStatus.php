<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut d'une action corrective.
 */
enum CorrectiveActionStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Verified = 'verified';
    case Ineffective = 'ineffective';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => 'Planifiée',
            self::InProgress => 'En cours',
            self::Done => 'Terminée',
            self::Verified => 'Vérifiée',
            self::Ineffective => 'Inefficace',
            self::Cancelled => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'warning',
            self::Done => 'info',
            self::Verified => 'success',
            self::Ineffective => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::InProgress], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Verified, self::Ineffective, self::Cancelled], true);
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
