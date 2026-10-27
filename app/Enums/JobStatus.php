<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum JobStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Active => 'Active',
            self::OnHold => 'Suspendue',
            self::Closed => 'Clôturée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Closed => 'danger',
        };
    }

    public function acceptsNewWorkOrders(): bool
    {
        return in_array($this, [self::Draft, self::Active], true);
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
