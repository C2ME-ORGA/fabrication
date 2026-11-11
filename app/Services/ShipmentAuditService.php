<?php

namespace App\Services;

use App\Enums\ShipmentAuditEvent;
use App\Models\Shipment;
use App\Models\ShipmentAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Journal d'audit append-only des expéditions.
 */
class ShipmentAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Shipment $shipment,
        ShipmentAuditEvent $event,
        ?User $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        array $metadata = [],
    ): ShipmentAuditLog {
        return ShipmentAuditLog::query()->create([
            'shipment_id' => $shipment->getKey(),
            'work_order_id' => $shipment->work_order_id,
            'shipment_number' => $shipment->number,
            'work_order_number' => $shipment->workOrder?->number,
            'user_id' => $actor?->getKey() ?? Auth::id(),
            'event' => $event->value,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
