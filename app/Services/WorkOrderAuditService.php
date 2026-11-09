<?php

namespace App\Services;

use App\Enums\WorkOrderAuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAuditLog;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Écriture du journal d'audit des ordres de fabrication.
 *
 * Extrait de WorkOrderService pour être partagé avec la gestion des pièces et,
 * plus tard, l'import de nomenclature. Le comportement reste identique : une
 * entrée est toujours rattachée au numéro de l'OF et écrite dans la transaction
 * métier de l'appelant.
 */
class WorkOrderAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        WorkOrder $workOrder,
        WorkOrderAuditEvent $event,
        ?User $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        array $metadata = [],
    ): void {
        // Un événement d'audit doit toujours être rattaché à un numéro d'OF,
        // même si l'OF a été supprimé entre-temps (le numéro est alors conservé).
        if ($workOrder->number === null) {
            throw new RuntimeException(
                'Impossible d\'écrire un événement d\'audit : l\'ordre de fabrication ne possède pas de numéro.'
            );
        }

        WorkOrderAuditLog::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'user_id' => $actor?->getKey() ?? Auth::id(),
            'event' => $event->value,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
