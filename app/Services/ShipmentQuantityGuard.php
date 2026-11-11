<?php

namespace App\Services;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentPackage;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * Quantité déjà répartie en colis pour une ligne.
     *
     * Un colis peut être exclu du calcul afin de simuler le remplacement de
     * sa répartition : la quantité déjà affectée à ce colis redevient
     * disponible pour la modification en cours.
     */
    public function packagedForLine(ShipmentLine $line, ?ShipmentPackage $exceptPackage = null): int
    {
        return (int) $line->packageLines()
            ->when(
                $exceptPackage !== null,
                fn ($query) => $query->where('shipment_package_id', '!=', $exceptPackage->getKey()),
            )
            ->sum('quantity');
    }

    public function remainingToPackageForLine(ShipmentLine $line, ?ShipmentPackage $exceptPackage = null): int
    {
        return max(0, $line->quantity_prepared - $this->packagedForLine($line, $exceptPackage));
    }

    /**
     * Lignes encore colisables d'une expédition.
     *
     * Le colis en cours de modification peut être exclu pour tenir compte de
     * la répartition qu'il contient déjà. Une ligne entièrement colisée
     * ailleurs n'est donc plus proposée, tandis qu'une ligne partiellement
     * disponible le reste.
     *
     * @return Collection<int, ShipmentLine>
     */
    public function packageableLines(Shipment $shipment, ?ShipmentPackage $exceptPackage = null): Collection
    {
        return $shipment->lines()
            ->get()
            ->filter(fn (ShipmentLine $line): bool => $this->remainingToPackageForLine($line, $exceptPackage) > 0)
            ->values();
    }
}
