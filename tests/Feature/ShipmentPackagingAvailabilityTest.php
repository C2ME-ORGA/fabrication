<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentQuantityGuard;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Disponibilité des lignes pour le sélecteur de colisage.
 *
 * Le calcul est centralisé dans ShipmentQuantityGuard et réutilisé par le
 * relation manager Filament. Le service reste la source de vérité pour
 * refuser tout dépassement, même si l'interface est contournée.
 */
class ShipmentPackagingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected WorkOrder $workOrder;

    protected WorkOrderItem $item;

    protected ShipmentService $shipments;

    protected ShipmentPackagingService $packaging;

    protected ShipmentQuantityGuard $quantities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->item = WorkOrderItem::factory()->forWorkOrder($this->workOrder)->create([
            'mark' => 'REP-DISPO',
            'designation' => 'Pièce de test',
            'quantity_planned' => 5,
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);

        $this->shipments = app(ShipmentService::class);
        $this->packaging = app(ShipmentPackagingService::class);
        $this->quantities = app(ShipmentQuantityGuard::class);
    }

    public function test_a_fully_available_line_is_proposed(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->admin);
        $package = $this->packaging->addPackage($shipment, ['code' => 'COLIS-A'], $this->admin);

        $this->assertSame([$line->getKey()], $this->availableLineIds($shipment, $package));
        $this->assertSame(5, $this->quantities->remainingToPackageForLine($line, $package));
    }

    public function test_a_partially_packaged_line_remains_proposed(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->admin);
        $first = $this->packaging->addPackage($shipment, ['code' => 'COLIS-A'], $this->admin);
        $second = $this->packaging->addPackage($shipment, ['code' => 'COLIS-B'], $this->admin);

        $this->packaging->assign($first, $line, 2, $this->admin);

        $this->assertSame([$line->getKey()], $this->availableLineIds($shipment, $second));
        $this->assertSame(3, $this->quantities->remainingToPackageForLine($line, $second));
    }

    public function test_a_fully_packaged_line_is_not_proposed_for_another_package(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->admin);
        $first = $this->packaging->addPackage($shipment, ['code' => 'COLIS-A'], $this->admin);
        $second = $this->packaging->addPackage($shipment, ['code' => 'COLIS-B'], $this->admin);

        $this->packaging->assign($first, $line, 5, $this->admin);

        $this->assertSame([], $this->availableLineIds($shipment, $second));
        $this->assertSame(0, $this->quantities->remainingToPackageForLine($line, $second));
    }

    public function test_a_package_under_edition_keeps_its_own_line_proposed(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->admin);
        $first = $this->packaging->addPackage($shipment, ['code' => 'COLIS-A'], $this->admin);
        $second = $this->packaging->addPackage($shipment, ['code' => 'COLIS-B'], $this->admin);

        $this->packaging->assign($first, $line, 3, $this->admin);
        $this->packaging->assign($second, $line, 2, $this->admin);

        // La ligne est entièrement colisée au niveau de l'expédition...
        $this->assertSame(0, $line->fresh()->remainingToPackage());

        // ... mais reste modifiable dans le colis qui la contient déjà.
        $this->assertSame([$line->getKey()], $this->availableLineIds($shipment, $second));
        $this->assertSame(2, $this->quantities->remainingToPackageForLine($line, $second));

        $this->packaging->assign($second, $line, 2, $this->admin);
        $this->assertSame(5, $line->fresh()->packagedQuantity());
    }

    public function test_modifying_a_package_cannot_exceed_the_available_quantity(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->admin);
        $first = $this->packaging->addPackage($shipment, ['code' => 'COLIS-A'], $this->admin);
        $second = $this->packaging->addPackage($shipment, ['code' => 'COLIS-B'], $this->admin);

        $this->packaging->assign($first, $line, 3, $this->admin);
        $this->packaging->assign($second, $line, 2, $this->admin);

        $this->expectException(ValidationException::class);

        // 3 déjà colisés ailleurs + 3 dans ce colis > 5 préparés.
        $this->packaging->assign($second, $line, 3, $this->admin);
    }

    public function test_the_service_rejects_an_overallocation_even_if_the_ui_filter_is_bypassed(): void
    {
        $shipment = $this->shipments->create($this->workOrder, $this->admin);
        $line = $this->shipments->addLine($shipment, $this->item, 5, $this->admin);
        $first = $this->packaging->addPackage($shipment, ['code' => 'COLIS-A'], $this->admin);
        $second = $this->packaging->addPackage($shipment, ['code' => 'COLIS-B'], $this->admin);

        $this->packaging->assign($first, $line, 5, $this->admin);

        // La ligne n'est plus proposée par l'interface...
        $this->assertSame([], $this->availableLineIds($shipment, $second));

        try {
            // ... mais le service doit refuser une affectation directe.
            $this->packaging->assign($second, $line, 1, $this->admin);
            $this->fail('Le dépassement de colisage aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(5, $line->fresh()->packagedQuantity());
        $this->assertSame(1, $line->fresh()->packageLines()->count());
    }

    /**
     * @return array<int, int>
     */
    protected function availableLineIds(Shipment $shipment, ShipmentPackage $package): array
    {
        return $this->quantities->packageableLines($shipment, $package)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
