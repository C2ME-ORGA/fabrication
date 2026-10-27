<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => 'Basse',
            self::Normal => 'Normale',
            self::High => 'Haute',
            self::Urgent => 'Urgente',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $priority): array => $options + [$priority->value => $priority->getLabel()],
            [],
        );
    }
}
