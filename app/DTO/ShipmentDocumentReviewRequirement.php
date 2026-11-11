<?php

namespace App\DTO;

use App\Enums\ShipmentDocumentReviewDecision;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ShipmentDocumentReview;
use App\Models\WorkOrderDocumentSnapshot;

/**
 * Révision documentaire nécessitant une décision P7.
 */
final readonly class ShipmentDocumentReviewRequirement
{
    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public ?WorkOrderDocumentSnapshot $baseline,
        public ?ShipmentDocumentReview $decision,
    ) {}

    public function isPending(): bool
    {
        return $this->decision === null;
    }

    public function isBlocked(): bool
    {
        return $this->decision?->decision === ShipmentDocumentReviewDecision::Blocked;
    }

    public function isSatisfied(): bool
    {
        return $this->decision !== null && ! $this->isBlocked();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isBlocked() => 'Bloquée',
            $this->isPending() => 'À examiner',
            default => 'Examinée',
        };
    }

    public function reference(): string
    {
        return (string) ($this->document->reference ?: $this->document->title);
    }
}
