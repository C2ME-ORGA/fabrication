<?php

namespace Tests\Feature\Concerns;

use App\Enums\WorkOrderStatus;
use App\Models\Shipment;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentService;
use App\Services\WorkOrderService;

trait BuildsPreparedShipment
{
    /**
     * @return array{0: WorkOrder, 1: WorkOrderItem}
     */
    protected function releasedWorkOrderWithItem(int $accepted = 5): array
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'quantity_planned' => $accepted,
            'quantity_produced' => $accepted,
            'quantity_rejected' => 0,
        ]);

        return [$workOrder, $item];
    }

    protected function preparedShipment(
        WorkOrder $workOrder,
        WorkOrderItem $item,
        int $quantity,
        bool $completePackaging = true,
        string $packageCode = 'COLIS-01',
    ): Shipment {
        $shipment = app(ShipmentService::class)->create($workOrder, $this->admin);
        $line = app(ShipmentService::class)->addLine($shipment, $item, $quantity, $this->admin);

        if ($completePackaging || $quantity > 1) {
            $package = app(ShipmentPackagingService::class)
                ->addPackage($shipment, ['code' => $packageCode], $this->admin);

            app(ShipmentPackagingService::class)->assign(
                $package,
                $line,
                $completePackaging ? $quantity : max(1, $quantity - 1),
                $this->admin,
            );
        }

        return app(ShipmentService::class)->prepare($shipment, $this->admin);
    }

    protected function completeWorkOrder(WorkOrder $workOrder): WorkOrder
    {
        $service = app(WorkOrderService::class);

        $workOrder = $service->transitionTo($workOrder, WorkOrderStatus::InProgress, $this->admin);
        $workOrder = $service->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $this->admin);

        return $service->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderItem, 2: Shipment}
     */
    protected function validationReadyShipment(
        int $accepted = 5,
        int $quantity = 3,
        bool $completePackaging = true,
    ): array {
        [$workOrder, $item] = $this->releasedWorkOrderWithItem($accepted);
        $shipment = $this->preparedShipment($workOrder, $item, $quantity, $completePackaging);
        $workOrder = $this->completeWorkOrder($workOrder->fresh());

        return [$workOrder, $item, $shipment->fresh()];
    }
}
