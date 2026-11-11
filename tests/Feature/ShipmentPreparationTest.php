<?php

namespace Tests\Feature;

use App\Enums\ShipmentAuditEvent;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Shipment;
use App\Models\ShipmentAuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ShipmentAuditService;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentNumberGenerator;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentQuantityGuard;
use App\Services\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ShipmentPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $workshop;

    protected User $quality;

    protected WorkOrder $workOrder;

    protected WorkOrderItem $item;

    protected ShipmentService $shipments;

    protected ShipmentPackagingService $packaging;

    protected ShipmentQuantityGuard $quantities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->workshop = User::factory()->workshopSupervisor()->create();
        $this->quality = User::factory()->qualityManager()->create();

        $this->workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->item = WorkOrderItem::factory()->forWorkOrder($this->workOrder)->create([
            'mark' => 'REP-001',
            'designation' => 'Poteau IPE 200',
            'quantity_planned' => 10,
            'quantity_produced' => 8,
            'quantity_rejected' => 1,
            'unit_weight_kg' => 22.4,
        ]);

        $this->shipments = app(ShipmentService::class);
        $this->packaging = app(ShipmentPackagingService::class);
        $this->quantities = app(ShipmentQuantityGuard::class);
    }

    public function test_a_shipment_is_created_as_a_draft_with_a_unique_number_and_audit(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop, [
            'carrier_name' => 'Transporteur Test',
            'notes' => 'Préparation matin.',
        ]);

        $this->assertSame(ShipmentStatus::Draft, $shipment->status);
        $this->assertMatchesRegularExpression('/^EXP-\d{4}-0001$/', $shipment->number);
        $this->assertSame($this->workOrder->getKey(), $shipment->work_order_id);
        $this->assertSame($this->workshop->getKey(), $shipment->created_by);
        $this->assertSame('Transporteur Test', $shipment->carrier_name);

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => ShipmentAuditEvent::Created->value,
            'user_id' => $this->workshop->getKey(),
        ]);
    }

    public function test_a_work_order_can_have_several_shipments_with_distinct_numbers(): void
    {
        $first = $this->shipments->create($this->workOrder, $this->workshop);
        $second = $this->shipments->create($this->workOrder, $this->admin);

        $this->assertNotSame($first->number, $second->number);
        $this->assertSame(2, $this->workOrder->fresh()->shipments()->count());
    }

    public function test_shipment_creation_is_refused_for_an_ineligible_work_order_status(): void
    {
        $draft = WorkOrder::factory()->status(WorkOrderStatus::Draft)->create();

        $this->expectException(ValidationException::class);

        $this->shipments->create($draft, $this->admin);
    }

    public function test_operator_and_quality_manager_cannot_create_a_shipment(): void
    {
        $operator = User::factory()->create();

        try {
            $this->shipments->create($this->workOrder, $operator);
            $this->fail('Un opérateur ne doit pas créer d\'expédition.');
        } catch (AuthorizationException) {
            // attendu
        }

        $this->expectException(AuthorizationException::class);
        $this->shipments->create($this->workOrder, $this->quality);
    }

    public function test_lines_snapshot_the_item_and_refuse_duplicates_or_foreign_items(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);

        $line = $this->shipments->addLine($shipment, $this->item, 6, $this->workshop);

        $this->assertSame('REP-001', $line->mark_snapshot);
        $this->assertSame('Poteau IPE 200', $line->designation_snapshot);
        $this->assertSame(6, $line->quantity_prepared);
        $this->assertSame(0, $line->quantity_shipped);
        $this->assertSame('22.400', $line->unit_weight_kg_snapshot);

        try {
            $this->shipments->addLine($shipment, $this->item, 1, $this->workshop);
            $this->fail('La ligne en double aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order_item_id', $exception->errors());
        }

        $otherItem = WorkOrderItem::factory()->forWorkOrder(
            WorkOrder::factory()->status(WorkOrderStatus::Released)->create()
        )->create();

        $this->expectException(ValidationException::class);
        $this->shipments->addLine($shipment, $otherItem, 1, $this->workshop);
    }

    public function test_line_quantities_must_be_positive(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);

        $this->expectException(ValidationException::class);
        $this->shipments->addLine($shipment, $this->item, 0, $this->workshop);
    }

    public function test_a_line_can_be_split_across_several_packages(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $line = $this->shipments->addLine($shipment, $this->item, 8, $this->workshop);

        $first = $this->packaging->addPackage($shipment, ['code' => 'COLIS-01'], $this->workshop);
        $second = $this->packaging->addPackage($shipment, ['code' => 'COLIS-02'], $this->workshop);

        $this->packaging->assign($first, $line, 3, $this->workshop);
        $this->packaging->assign($second, $line, 5, $this->workshop);

        $this->assertSame(8, $line->packagedQuantity());
        $this->assertSame(0, $line->remainingToPackage());
        $this->packaging->assertCompleteForValidation($shipment);
    }

    public function test_a_package_can_contain_several_lines(): void
    {
        $secondItem = WorkOrderItem::factory()->forWorkOrder($this->workOrder)->create([
            'mark' => 'REP-002',
            'quantity_produced' => 4,
            'quantity_rejected' => 0,
        ]);

        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $firstLine = $this->shipments->addLine($shipment, $this->item, 2, $this->workshop);
        $secondLine = $this->shipments->addLine($shipment, $secondItem, 4, $this->workshop);
        $package = $this->packaging->addPackage($shipment, ['code' => 'COLIS-01'], $this->workshop);

        $this->packaging->assign($package, $firstLine, 2, $this->workshop);
        $this->packaging->assign($package, $secondLine, 4, $this->workshop);

        $this->assertSame(2, $package->packageLines()->count());
        $this->assertStringContainsString('REP-001', $package->contentSummary());
        $this->assertStringContainsString('REP-002', $package->contentSummary());
    }

    public function test_packaging_cannot_exceed_the_prepared_quantity(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->workshop);
        $package = $this->packaging->addPackage($shipment, [], $this->workshop);

        $this->packaging->assign($package, $line, 5, $this->workshop);

        $this->expectException(ValidationException::class);
        $this->packaging->assign($package, $line, 6, $this->workshop);
    }

    public function test_a_package_and_a_line_must_belong_to_the_same_shipment(): void
    {
        $first = $this->shipments->create($this->workOrder, $this->workshop);
        $second = $this->shipments->create($this->workOrder, $this->workshop);

        $lineFromSecond = $this->shipments->addLine($second, $this->item, 2, $this->workshop);
        $packageFromFirst = $this->packaging->addPackage($first, [], $this->workshop);

        $this->expectException(ValidationException::class);
        $this->packaging->assign($packageFromFirst, $lineFromSecond, 1, $this->workshop);
    }

    public function test_package_codes_are_unique_within_a_shipment(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $this->packaging->addPackage($shipment, ['code' => 'COLIS-01'], $this->workshop);

        $this->expectException(ValidationException::class);
        $this->packaging->addPackage($shipment, ['code' => 'COLIS-01'], $this->workshop);
    }

    public function test_preparation_does_not_reserve_quantities_or_change_the_work_order(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $this->shipments->addLine($shipment, $this->item, 5, $this->workshop);
        $prepared = $this->shipments->prepare($shipment, $this->workshop);

        $this->assertSame(ShipmentStatus::Prepared, $prepared->status);
        $this->assertNotNull($prepared->prepared_at);
        $this->assertSame($this->workshop->getKey(), $prepared->prepared_by);

        $this->assertSame(0, $this->quantities->validatedForItem($this->item));
        $this->assertSame(0, $this->quantities->shippedForItem($this->item));
        $this->assertSame(7, $this->quantities->acceptedQuantity($this->item));
        $this->assertSame(7, $this->quantities->availableForItem($this->item));
        $this->assertSame(WorkOrderStatus::Released, $this->workOrder->fresh()->status);
    }

    public function test_preparation_can_exceed_the_accepted_quantity_without_reserving_it(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);

        $line = $this->shipments->addLine($shipment, $this->item, 12, $this->workshop);

        $this->assertSame(12, $line->quantity_prepared);
        $this->assertSame(0, $this->quantities->remainingToPrepareForItem($this->item));
        $this->assertSame(7, $this->quantities->availableForItem($this->item));
    }

    public function test_a_prepared_shipment_must_be_reopened_before_editing(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $shipment = $this->shipments->prepare($shipment, $this->workshop);

        try {
            $this->shipments->addLine($shipment, $this->item, 1, $this->workshop);
            $this->fail('Une expédition préparée ne doit pas être modifiable directement.');
        } catch (AuthorizationException) {
            // La Policy refuse toute modification hors brouillon.
        }

        $this->assertDatabaseCount('shipment_lines', 0);

        $reopened = $this->shipments->reopen($shipment, $this->workshop);

        $this->assertSame(ShipmentStatus::Draft, $reopened->status);
        $this->assertNull($reopened->prepared_at);
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => ShipmentAuditEvent::Reopened->value,
        ]);
    }

    public function test_line_quantity_cannot_drop_below_the_packaged_quantity(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->workshop);
        $package = $this->packaging->addPackage($shipment, [], $this->workshop);
        $this->packaging->assign($package, $line, 3, $this->workshop);

        $this->expectException(ValidationException::class);
        $this->shipments->updateLine($line, 2, $this->workshop);
    }

    public function test_removing_a_line_removes_its_packaging_allocations(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->workshop);
        $package = $this->packaging->addPackage($shipment, [], $this->workshop);
        $this->packaging->assign($package, $line, 3, $this->workshop);

        $this->shipments->removeLine($line, $this->workshop);

        $this->assertDatabaseCount('shipment_lines', 0);
        $this->assertDatabaseCount('shipment_package_lines', 0);
    }

    public function test_incomplete_packaging_is_detected(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->workshop);
        $package = $this->packaging->addPackage($shipment, [], $this->workshop);
        $this->packaging->assign($package, $line, 2, $this->workshop);

        $this->expectException(ValidationException::class);
        $this->packaging->assertCompleteForValidation($shipment);
    }

    public function test_shipment_audit_logs_are_immutable(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $log = ShipmentAuditLog::query()->sole();

        $this->expectException(RuntimeException::class);
        $log->delete();
    }

    public function test_delivery_note_reference_is_unique_per_work_order(): void
    {
        $this->shipments->create($this->workOrder, $this->workshop, [
            'delivery_note_reference' => 'BL-001',
        ]);

        try {
            $this->shipments->create($this->workOrder, $this->workshop, [
                'delivery_note_reference' => 'BL-001',
            ]);
            $this->fail('La référence BL en doublon aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_note_reference', $exception->errors());
        }

        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_duplicate_delivery_note_reference_on_update_is_refused_without_partial_save(): void
    {
        $first = $this->shipments->create($this->workOrder, $this->workshop, [
            'delivery_note_reference' => 'BL-001',
        ]);
        $second = $this->shipments->create($this->workOrder, $this->workshop, [
            'delivery_note_reference' => 'BL-002',
        ]);

        try {
            $this->shipments->updateHeader($second, [
                'delivery_note_reference' => 'BL-001',
            ], $this->workshop);
            $this->fail('La référence BL en doublon aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_note_reference', $exception->errors());
        }

        $this->assertSame('BL-002', $second->fresh()->delivery_note_reference);
        $this->assertSame('BL-001', $first->fresh()->delivery_note_reference);
    }

    public function test_database_unique_conflict_is_translated_to_a_validation_error(): void
    {
        $this->shipments->create($this->workOrder, $this->workshop, [
            'delivery_note_reference' => 'BL-001',
        ]);

        $service = new class(app(ShipmentNumberGenerator::class), app(ShipmentAuditService::class), app(ShipmentQuantityGuard::class), app(ShipmentDocumentService::class)) extends ShipmentService
        {
            protected function assertDeliveryNoteReferenceAvailable(
                int $workOrderId,
                ?string $reference,
                ?int $ignoreShipmentId = null,
            ): void {
                // Simule une course : le pré-contrôle ne voit pas encore le doublon.
            }
        };

        try {
            $service->create($this->workOrder, $this->workshop, [
                'delivery_note_reference' => 'BL-001',
            ]);
            $this->fail('Le conflit d’unicité aurait dû être traduit en erreur métier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_note_reference', $exception->errors());
        }

        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_quality_manager_can_view_but_not_edit_a_shipment(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);

        $this->assertTrue($this->quality->can('view', $shipment));
        $this->assertFalse($this->quality->can('update', $shipment));

        $this->expectException(AuthorizationException::class);
        $this->shipments->updateHeader($shipment, ['notes' => 'Tentative'], $this->quality);
    }

    public function test_operator_cannot_view_a_shipment(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->workshop);
        $operator = User::factory()->create();

        $this->assertFalse($operator->can('view', $shipment));
    }
}
