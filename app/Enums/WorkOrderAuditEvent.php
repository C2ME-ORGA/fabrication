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
    case ItemCreated = 'item_created';
    case ItemUpdated = 'item_updated';
    case ItemDeleted = 'item_deleted';
    case ItemMerged = 'item_merged';
    case ItemProductionRecorded = 'item_production_recorded';
    case ItemStatusChanged = 'item_status_changed';
    case BomImported = 'bom_imported';
    case QualityClosureBlocked = 'quality_closure_blocked';
    case QualityReleased = 'quality_released';
    case DocumentReviewDecided = 'document_review_decided';
    case PdfGenerated = 'pdf_generated';

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
            self::ItemCreated => 'Création d\'une pièce',
            self::ItemUpdated => 'Modification d\'une pièce',
            self::ItemDeleted => 'Suppression d\'une pièce',
            self::ItemMerged => 'Fusion de repères',
            self::ItemProductionRecorded => 'Saisie de production',
            self::ItemStatusChanged => 'Changement d\'état d\'une pièce',
            self::BomImported => 'Import de nomenclature',
            self::QualityClosureBlocked => 'Libération ou clôture bloquée pour motif qualité',
            self::QualityReleased => 'Libération qualité enregistrée',
            self::DocumentReviewDecided => 'Décision de revue documentaire',
            self::PdfGenerated => 'Génération du PDF de l\'OF',
        };
    }
}
