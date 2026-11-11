<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Événements du journal d'audit des expéditions.
 *
 * Le Lot 3 n'émet que les événements de préparation. Les événements de
 * validation, de départ et d'annulation seront ajoutés au Lot 4.
 */
enum ShipmentAuditEvent: string implements HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case Prepared = 'prepared';
    case Reopened = 'reopened';
    case PackageCreated = 'package_created';
    case PackageUpdated = 'package_updated';
    case PackageRemoved = 'package_removed';
    case LineAdded = 'line_added';
    case LineUpdated = 'line_updated';
    case LineRemoved = 'line_removed';
    case PackagingAssigned = 'packaging_assigned';
    case PackagingRemoved = 'packaging_removed';
    case Validated = 'validated';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
    case DocumentGenerated = 'document_generated';
    case DocumentCancelled = 'document_cancelled';
    case DocumentReviewDecided = 'document_review_decided';
    case ValidationRefused = 'validation_refused';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Création de l\'expédition',
            self::Updated => 'Modification de l\'expédition',
            self::Prepared => 'Expédition préparée',
            self::Reopened => 'Réouverture de la préparation',
            self::PackageCreated => 'Création d\'un colis',
            self::PackageUpdated => 'Modification d\'un colis',
            self::PackageRemoved => 'Suppression d\'un colis',
            self::LineAdded => 'Ajout d\'une ligne',
            self::LineUpdated => 'Modification d\'une ligne',
            self::LineRemoved => 'Suppression d\'une ligne',
            self::PackagingAssigned => 'Répartition d\'une pièce dans un colis',
            self::PackagingRemoved => 'Retrait d\'une répartition',
            self::Validated => 'Expédition validée',
            self::Shipped => 'Départ de l\'expédition enregistré',
            self::Cancelled => 'Expédition annulée',
            self::DocumentGenerated => 'Document d\'expédition généré',
            self::DocumentCancelled => 'Document d\'expédition annulé',
            self::DocumentReviewDecided => 'Décision de revue documentaire',
            self::ValidationRefused => 'Validation d\'expédition refusée',
        };
    }
}
