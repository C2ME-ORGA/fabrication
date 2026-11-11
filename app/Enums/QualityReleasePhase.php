<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Contexte dans lequel une preuve de libération qualité est enregistrée.
 */
enum QualityReleasePhase: string implements HasLabel
{
    case Closure = 'closure';
    case HistoricalReview = 'historical_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::Closure => 'Clôture de l\'OF',
            self::HistoricalReview => 'Revue historique',
        };
    }
}
