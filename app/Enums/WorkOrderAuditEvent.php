<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Types d'événements enregistrés dans le journal d'audit des ordres de fabrication.
 *
 * Un événement est créé pour la création de l'OF et pour chaque transition
 * d'état autorisée. Un événement par transition, jamais de doublon.
 */
enum WorkOrderAuditEvent: string implements HasLabel
{
    case Created = 'created';
    case Ready = 'ready';
    case Released = 'released';
    case Started = 'started';
    case QualityCheck = 'quality_check';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Création de l\'OF',
            self::Ready => 'Passage à l\'état « Prêt »',
            self::Released => 'Libération de l\'OF',
            self::Started => 'Démarrage des travaux',
            self::QualityCheck => 'Passage en contrôle qualité',
            self::Completed => 'Fin de l\'OF',
            self::Cancelled => 'Annulation de l\'OF',
        };
    }
}
