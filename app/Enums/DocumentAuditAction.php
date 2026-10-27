<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentAuditAction: string implements HasLabel
{
    case DocumentCreated = 'document_created';
    case DocumentUpdated = 'document_updated';
    case DocumentArchived = 'document_archived';
    case VersionUploaded = 'version_uploaded';
    case VersionSubmitted = 'version_submitted';
    case VersionApproved = 'version_approved';
    case VersionRejected = 'version_rejected';
    case VersionSuperseded = 'version_superseded';
    case SnapshotCaptured = 'snapshot_captured';
    case DocumentsReReviewed = 'documents_re_reviewed';
    case VersionDownloaded = 'version_downloaded';

    public function getLabel(): string
    {
        return match ($this) {
            self::DocumentCreated => 'Document créé',
            self::DocumentUpdated => 'Métadonnées modifiées',
            self::DocumentArchived => 'Document archivé',
            self::VersionUploaded => 'Version téléversée',
            self::VersionSubmitted => 'Version soumise à validation',
            self::VersionApproved => 'Version approuvée',
            self::VersionRejected => 'Version refusée',
            self::VersionSuperseded => 'Version remplacée',
            self::SnapshotCaptured => 'Références documentaires figées',
            self::DocumentsReReviewed => 'Documents remis en revue',
            self::VersionDownloaded => 'Version téléchargée',
        };
    }
}
