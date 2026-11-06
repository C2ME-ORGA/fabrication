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
    case BeApproved = 'be_approved';
    case BeApprovalInvalidated = 'be_approval_invalidated';
    case BeApprovalObsolete = 'be_approval_obsolete';
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
            self::BeApproved => 'Approbation du bureau d\'études',
            self::BeApprovalInvalidated => 'Invalidation de l\'approbation BE',
            self::BeApprovalObsolete => 'Obsolescence de l\'approbation BE',
            self::Ready => 'Passage à l\'état « Prêt »',
            self::Released => 'Libération de l\'OF',
            self::Started => 'Démarrage des travaux',
            self::QualityCheck => 'Passage en contrôle qualité',
            self::Completed => 'Fin de l\'OF',
            self::Cancelled => 'Annulation de l\'OF',
        };
    }
}
