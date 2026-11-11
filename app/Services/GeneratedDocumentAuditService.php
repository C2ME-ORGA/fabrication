<?php

namespace App\Services;

use App\Enums\ShipmentAuditEvent;
use App\Enums\WorkOrderAuditEvent;
use App\Models\GeneratedDocument;
use App\Models\User;

/**
 * Trace les générations PDF dans le journal approprié.
 *
 * Les PDF d'OF restent dans work_order_audit_logs ; les BL et listes de
 * colisage sont tracés dans shipment_audit_logs.
 */
class GeneratedDocumentAuditService
{
    public function __construct(
        protected WorkOrderAuditService $workOrderAudit,
        protected ShipmentAuditService $shipmentAudit,
    ) {}

    public function recordGenerated(GeneratedDocument $document, ?User $actor = null): void
    {
        $metadata = [
            'generated_document_id' => (int) $document->getKey(),
            'reference' => $document->reference,
            'type' => $document->type->value,
            'version' => $document->version,
            'sha256' => $document->sha256,
            'of_revision' => $document->of_revision,
        ];

        if ($document->shipment_id !== null) {
            $document->loadMissing('shipment');

            if ($document->shipment !== null) {
                $this->shipmentAudit->record(
                    $document->shipment,
                    ShipmentAuditEvent::DocumentGenerated,
                    $actor,
                    null,
                    null,
                    "Document {$document->reference} généré et archivé.",
                    $metadata,
                );

                return;
            }
        }

        $document->loadMissing('workOrder');

        if ($document->workOrder === null) {
            return;
        }

        $this->workOrderAudit->record(
            $document->workOrder,
            WorkOrderAuditEvent::PdfGenerated,
            $actor,
            null,
            null,
            "PDF {$document->reference} généré et archivé.",
            $metadata,
        );
    }
}
