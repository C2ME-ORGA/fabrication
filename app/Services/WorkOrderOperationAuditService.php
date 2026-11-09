<?php

namespace App\Services;

use App\Enums\WorkshopOperationAuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationAuditLog;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Écriture du journal d'audit immuable des opérations d'un OF.
 *
 * Les entrées sont toujours créées dans la transaction métier de l'appelant.
 * Une entrée peut concerner une opération précise ou l'OF dans son ensemble
 * (affectation ou remplacement d'une gamme).
 */
class WorkOrderOperationAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?WorkOrderOperation $operation,
        WorkOrder $workOrder,
        WorkshopOperationAuditEvent $event,
        ?User $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        array $metadata = [],
    ): void {
        if ($workOrder->number === null) {
            throw new RuntimeException(
                'Impossible d\'écrire un événement d\'audit : l\'ordre de fabrication ne possède pas de numéro.'
            );
        }

        WorkOrderOperationAuditLog::query()->create([
            'work_order_operation_id' => $operation?->getKey(),
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'operation_code' => $operation?->code,
            'user_id' => $actor?->getKey() ?? Auth::id(),
            'event' => $event->value,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
