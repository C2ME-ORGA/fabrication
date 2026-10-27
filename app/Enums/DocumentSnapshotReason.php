<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentSnapshotReason: string implements HasLabel
{
    case Release = 'release';
    case ReReview = 're_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::Release => "Libération de l'OF",
            self::ReReview => 'Remise en revue',
        };
    }
}
