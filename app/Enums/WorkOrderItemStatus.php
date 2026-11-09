<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État de fabrication d'une pièce d'un ordre de fabrication.
 *
 * Les états de production (`todo`, `in_progress`, `partially_produced`,
 * `produced`) sont pilotés par les quantités enregistrées. `closed` et
 * `cancelled` sont des états finaux qui nécessitent une action explicite.
 */
enum WorkOrderItemStatus: string implements HasColor, HasLabel
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case PartiallyProduced = 'partially_produced';
    case Produced = 'produced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Todo => 'À fabriquer',
            self::InProgress => 'En fabrication',
            self::PartiallyProduced => 'Partiellement fabriquée',
            self::Produced => 'Fabriquée',
            self::Closed => 'Clôturée',
            self::Cancelled => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::InProgress => 'info',
            self::PartiallyProduced => 'warning',
            self::Produced => 'success',
            self::Closed => 'primary',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Transitions autorisées par le service. Les retours vers un état de
     * production antérieur permettent de corriger une saisie tant que la
     * pièce n'est pas clôturée ou annulée.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Une saisie de production peut être faite directement sans
            // démarrer explicitement la pièce.
            self::Todo => [self::InProgress, self::PartiallyProduced, self::Produced, self::Cancelled],
            self::InProgress => [self::PartiallyProduced, self::Produced, self::Cancelled],
            self::PartiallyProduced => [self::InProgress, self::Produced, self::Cancelled],
            self::Produced => [self::InProgress, self::PartiallyProduced, self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * États finaux : aucune production ni transition n'est plus possible.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
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
