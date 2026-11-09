<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État d'une opération de fabrication d'un ordre de fabrication.
 *
 * `pending` et `ready` sont dérivés des dépendances : une opération devient
 * `ready` lorsque toutes ses dépendances sont terminées. Les autres états
 * résultent exclusivement d'une action métier explicite et auditée.
 */
enum WorkshopOperationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Ready => 'Prête',
            self::InProgress => 'En cours',
            self::Paused => 'En pause',
            self::Blocked => 'Bloquée',
            self::Completed => 'Terminée',
            self::Cancelled => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Ready => 'info',
            self::InProgress => 'warning',
            self::Paused => 'warning',
            self::Blocked => 'danger',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Transitions autorisées par le service métier.
     *
     * Le passage `pending` → `ready` n'est possible que si les dépendances
     * sont satisfaites ; le déblocage recalcule explicitement `ready` ou
     * `pending` selon l'état des dépendances.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Ready],
            self::Ready => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Paused, self::Blocked, self::Completed],
            self::Paused => [self::InProgress, self::Blocked, self::Cancelled],
            self::Blocked => [self::Ready, self::Pending, self::InProgress, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * États définitifs : une opération terminée ou annulée ne peut plus être
     * rouverte par une action standard.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * États pour lesquels une session de chronométrage peut être active.
     */
    public function canBeTimed(): bool
    {
        return in_array($this, [self::InProgress, self::Paused], true);
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
