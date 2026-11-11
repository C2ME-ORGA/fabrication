<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShipmentPackageType: string implements HasLabel
{
    case Pallet = 'pallet';
    case Crate = 'crate';
    case Bundle = 'bundle';
    case Box = 'box';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pallet => 'Palette',
            self::Crate => 'Caisse',
            self::Bundle => 'Faisceau',
            self::Box => 'Carton',
            self::Other => 'Autre',
        };
    }
}
