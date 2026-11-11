<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShipmentStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case Validated = 'validated';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Prepared => 'Préparée',
            self::Validated => 'Validée',
            self::Shipped => 'Expédiée',
            self::Cancelled => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Prepared => 'info',
            self::Validated => 'success',
            self::Shipped => 'primary',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Le Lot 3 n'active que les transitions de préparation. Les transitions
     * de validation, de départ et d'annulation définitive relèvent du Lot 4.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Prepared, self::Cancelled],
            self::Prepared => [self::Draft, self::Validated, self::Cancelled],
            self::Validated => [self::Shipped, self::Cancelled],
            self::Shipped, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
