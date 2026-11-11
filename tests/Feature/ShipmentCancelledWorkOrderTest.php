<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentService;
use App\Services\WorkOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentCancelledWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $workshop;

    protected User $quality;

    protected WorkOrder $workOrder;

    protected WorkOrderItem $item;

    protected ShipmentService $shipments;

    protected ShipmentPackagingService $packaging;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->workshop = User::factory()->workshopSupervisor()->create();
        $this->quality = User::factory()->qualityManager()->create();

        $this->workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->item = WorkOrderItem::factory()->forWorkOrder($this->workOrder)->create([
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);

        $this->shipments = app(ShipmentService::class);
        $this->packaging = app(ShipmentPackagingService::class);
    }

    public function test_adding_a_line_after_cancellation_is_refused(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $this->cancelWorkOrder();

        $this->expectException(AuthorizationException::class);

        $this->shipments->addLine($shipment->fresh(), $this->item->fresh(), 1, $this->admin);
    }

    public function test_updating_a_line_after_cancellation_is_refused(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 3, $this->admin);
        $this->cancelWorkOrder();

        $this->expectException(AuthorizationException::class);

        $this->shipments->updateLine($line->fresh(), 2, $this->admin);
    }

    public function test_adding_a_package_after_cancellation_is_refused(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $this->cancelWorkOrder();

        $this->expectException(AuthorizationException::class);

        $this->packaging->addPackage($shipment->fresh(), ['code' => 'COLIS-01'], $this->admin);
    }

    public function test_packaging_assignment_after_cancellation_is_refused(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 3, $this->admin);
        $package = $this->packaging->addPackage($shipment, ['code' => 'COLIS-01'], $this->admin);
        $this->cancelWorkOrder();

        $this->expectException(AuthorizationException::class);

        $this->packaging->assign($package->fresh(), $line->fresh(), 1, $this->admin);
    }

    public function test_preparing_a_shipment_after_cancellation_is_refused(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $this->cancelWorkOrder();

        $this->expectException(AuthorizationException::class);

        $this->shipments->prepare($shipment->fresh(), $this->admin);
    }

    public function test_reopening_a_prepared_shipment_after_cancellation_is_refused(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $this->shipments->prepare($shipment, $this->admin);
        $this->cancelWorkOrder();

        $this->expectException(AuthorizationException::class);

        $this->shipments->reopen($shipment->fresh(), $this->admin);
    }

    public function test_cancelled_shipment_remains_readable_but_is_not_editable(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $this->cancelWorkOrder();

        $fresh = $shipment->fresh();

        $this->assertFalse($fresh->isEditable());
        $this->assertTrue($fresh->workOrder->status === WorkOrderStatus::Cancelled);
        $this->assertTrue($this->admin->can('view', $fresh));
        $this->assertTrue($this->workshop->can('view', $fresh));
        $this->assertTrue($this->quality->can('view', $fresh));
    }

    public function test_prepared_shipment_cannot_be_reopened_after_cancellation(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $prepared = $this->shipments->prepare($shipment, $this->admin);
        $this->cancelWorkOrder();

        $this->assertFalse($prepared->fresh()->canReopen());
    }

    protected function cancelWorkOrder(): void
    {
        app(WorkOrderService::class)->transitionTo(
            $this->workOrder->fresh(),
            WorkOrderStatus::Cancelled,
            $this->admin,
            'Annulation de test.',
        );

        $this->workOrder = $this->workOrder->fresh();
    }
}
