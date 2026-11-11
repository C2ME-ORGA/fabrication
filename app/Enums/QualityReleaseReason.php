<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Motif d'enregistrement d'une preuve de libération qualité.
 */
enum QualityReleaseReason: string implements HasLabel
{
    case Completion = 'completion';
    case ReRelease = 're_release';
    case HistoricalReview = 'historical_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::Completion => 'Clôture de l\'OF',
            self::ReRelease => 'Re-libération',
            self::HistoricalReview => 'Revue historique',
        };
    }
}
