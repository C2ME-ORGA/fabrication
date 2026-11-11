<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShipmentDocumentReviewDecision: string implements HasColor, HasLabel
{
    case NoImpact = 'no_impact';
    case Reviewed = 'reviewed';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoImpact => 'Sans impact',
            self::Reviewed => 'Examinée',
            self::Blocked => 'Bloquante',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NoImpact => 'info',
            self::Reviewed => 'success',
            self::Blocked => 'danger',
        };
    }
}
