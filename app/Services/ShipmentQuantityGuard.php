<?php

namespace App\Services;

use App\Enums\ShipmentStatus;
use App\Models\ShipmentLine;
use App\Models\WorkOrderItem;

/**
 * Calculs informatifs des quantités préparées, validées, expédiées et
 * restantes.
 *
 * Le Lot 3 ne réserve aucune quantité : la préparation peut donc dépasser
 * temporairement la quantité acceptée. Le contrôle définitif et la réservation
 * appartiennent à la validation du Lot 4.
 */
class ShipmentQuantityGuard
{
    public function acceptedQuantity(WorkOrderItem $item): int
    {
        return max(0, (int) $item->accepted_quantity);
    }

    public function preparedForItem(WorkOrderItem $item): int
    {
        return (int) ShipmentLine::query()
            ->where('work_order_item_id', $item->getKey())
            ->whereHas('shipment', fn ($query) => $query->where('status', '!=', ShipmentStatus::Cancelled->value))
            ->sum('quantity_prepared');
    }

    public function validatedForItem(WorkOrderItem $item): int
    {
        return (int) ShipmentLine::query()
            ->where('work_order_item_id', $item->getKey())
            ->whereHas('shipment', fn ($query) => $query->where('status', ShipmentStatus::Validated->value))
            ->sum('quantity_prepared');
    }

    public function shippedForItem(WorkOrderItem $item): int
    {
        return (int) ShipmentLine::query()
            ->where('work_order_item_id', $item->getKey())
            ->whereHas('shipment', fn ($query) => $query->where('status', ShipmentStatus::Shipped->value))
            ->sum('quantity_shipped');
    }

    public function availableForItem(WorkOrderItem $item): int
    {
        return max(0, $this->acceptedQuantity($item)
            - $this->validatedForItem($item)
            - $this->shippedForItem($item));
    }

    public function remainingToPrepareForItem(WorkOrderItem $item): int
    {
        return max(0, $this->acceptedQuantity($item) - $this->preparedForItem($item));
    }

    public function remainingToShipForItem(WorkOrderItem $item): int
    {
        return max(0, $this->acceptedQuantity($item) - $this->shippedForItem($item));
    }

    public function packagedForLine(ShipmentLine $line): int
    {
        return (int) $line->packageLines()->sum('quantity');
    }

    public function remainingToPackageForLine(ShipmentLine $line): int
    {
        return max(0, $line->quantity_prepared - $this->packagedForLine($line));
    }
}
