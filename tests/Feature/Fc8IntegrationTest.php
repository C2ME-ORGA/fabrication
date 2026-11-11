<?php

namespace Tests\Feature;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\ShipmentDocumentReviewDecision;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\GeneratedDocument;
use App\Models\Job;
use App\Models\ShipmentDocumentReview;
use App\Models\User;
use App\Models\WorkOrderQualityRelease;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentQuantityGuard;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationService;
use App\Services\WorkOrderItemService;
use App\Services\WorkOrderPdfGenerationService;
use App\Services\WorkOrderQualityReleaseService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsDocumentReviewScenarios;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

/**
 * Parcours transversal FC-8 : création OF → PDF → contrôle qualité →
 * libération/clôture → expédition → colisage → P7 → validation → BL/liste de
 * colisage → départ.
 *
 * Les règles détaillées restent couvertes par les suites spécialisées ; ce
 * test vérifie que les modules s'enchaînent réellement avec les services
 * métier. L'affaire et le contrôle qualité sont préparés par les factories et
 * helpers de test ; les transitions OF, la production, l'expédition, le
 * colisage, la décision P7, la validation et le départ passent par les
 * services réels, et l'éligibilité qualité est recalculée par le service.
 */
class Fc8IntegrationTest extends TestCase
{
    use BuildsDocumentReviewScenarios;
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    protected User $admin;

    protected User $quality;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');
        Storage::fake('shipment_audit');

        $this->admin = User::factory()->admin()->create();
        $this->quality = User::factory()->qualityManager()->create();
    }

    public function test_fc8_end_to_end_from_work_order_creation_to_departure(): void
    {
        $workOrders = app(WorkOrderService::class);
        $items = app(WorkOrderItemService::class);
        $shipments = app(ShipmentService::class);
        $packaging = app(ShipmentPackagingService::class);
        $documents = app(ShipmentDocumentService::class);

        // 1. Création réelle de l'OF et de sa pièce.
        $job = Job::factory()->active()->create();

        $workOrder = $workOrders->create([
            'job_id' => $job->getKey(),
            'title' => 'OF intégration Lot 5',
        ], $this->admin);

        $this->assertSame(WorkOrderStatus::Draft, $workOrder->status);

        $item = $items->create($workOrder, [
            'mark' => 'REP-INT-01',
            'designation' => 'Poteau intégration',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 5,
            'unit_weight_kg' => 22.4,
        ], $this->admin);

        $workOrder = $workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $workOrders->approveByBE($workOrder, $this->admin);
        $workOrder = $workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);

        // 2. Exécution : production réelle de la pièce.
        $workOrder = $workOrders->transitionTo($workOrder, WorkOrderStatus::InProgress, $this->admin);
        $item = $items->recordProduction($item, 5, 0, $this->admin);

        $this->assertSame(5, $item->accepted_quantity);

        // 3. Expédition : création, ligne, colis et répartition.
        $shipment = $shipments->create($workOrder, $this->admin);
        $line = $shipments->addLine($shipment, $item, 3, $this->admin);
        $package = $packaging->addPackage($shipment, ['code' => 'COLIS-INT-01'], $this->admin);
        $packaging->assign($package, $line, 3, $this->admin);
        $shipment = $shipments->prepare($shipment, $this->admin);

        $this->assertSame(ShipmentStatus::Prepared, $shipment->status);
        $this->assertSame(3, $line->fresh()->quantity_prepared);
        $this->assertSame(3, $line->fresh()->packagedQuantity());

        $guard = app(ShipmentQuantityGuard::class);
        $this->assertSame(3, $guard->preparedForItem($item));
        $this->assertSame(0, $guard->validatedForItem($item));
        $this->assertSame(0, $guard->shippedForItem($item));
        $this->assertSame(5, $guard->availableForItem($item));

        // 4. Contrôle qualité exécuté et validé avant la clôture.
        [$control, $point] = $this->createControl($workOrder);
        $this->addValidatedResult($workOrder, $point, true);

        $this->assertSame(1, $control->fresh()->points()->count());

        $workOrder = $workOrders->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $this->admin);
        $workOrder = $workOrders->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);

        $this->assertSame(WorkOrderStatus::Completed, $workOrder->status);

        // 5. Libération qualité persistée et visa du PDF.
        $release = WorkOrderQualityRelease::query()
            ->where('work_order_id', $workOrder->getKey())
            ->sole();

        $this->assertTrue($release->eligible);
        $this->assertTrue($release->requirements_present);
        $this->assertTrue(app(WorkOrderQualityReleaseService::class)->verifySnapshot($release));

        $firstPdf = app(WorkOrderPdfGenerationService::class)->generate($workOrder, $this->admin);

        $this->assertSame(GeneratedDocumentStatus::Archived, $firstPdf->status);
        $this->assertSame($workOrder->getKey(), $firstPdf->work_order_id);
        $this->assertSame('effectue', $firstPdf->visa_snapshot['qualite']['status']);
        $this->assertSame($release->getKey(), $firstPdf->visa_snapshot['qualite']['evidence_id']);
        $this->assertTrue(Storage::disk('generated_documents')->exists($firstPdf->path));
        $this->assertSame(
            hash('sha256', Storage::disk('generated_documents')->get($firstPdf->path)),
            $firstPdf->sha256,
        );

        // 6. Une génération ultérieure conserve l'archive précédente.
        $secondPdf = app(WorkOrderPdfGenerationService::class)->generate($workOrder->fresh(), $this->admin);

        $this->assertSame(2, $secondPdf->version);
        $this->assertSame(GeneratedDocumentStatus::Superseded, $firstPdf->fresh()->status);
        $this->assertSame(GeneratedDocumentStatus::Archived, $secondPdf->status);
        $this->assertTrue(Storage::disk('generated_documents')->exists($firstPdf->path));
        $this->assertTrue(Storage::disk('generated_documents')->exists($secondPdf->path));

        $this->actingAs($this->admin)
            ->get(route('generated-documents.view', $secondPdf))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // 7. Décision P7 réelle pour la révision apparue après libération.
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $review = $documents->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::NoImpact,
            $this->quality,
            'Aucun impact sur la fabrication ou l’expédition.',
            $shipment,
        );

        $this->assertSame(ShipmentDocumentReviewDecision::NoImpact, $review->decision);
        $this->assertSame(1, ShipmentDocumentReview::query()->count());

        $requirements = $documents->requirements($workOrder);

        $this->assertCount(1, $requirements);
        $this->assertTrue($requirements->first()->isSatisfied());

        // 8. Validation : réservation et documents d'expédition.
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $this->assertSame(ShipmentStatus::Validated, $validated->status);
        $this->assertSame($this->admin->getKey(), $validated->validated_by);
        $this->assertNotNull($validated->validated_at);
        $this->assertSame($validated->number.'-BL', $validated->delivery_note_reference);

        $shipmentDocuments = GeneratedDocument::query()
            ->where('shipment_id', $validated->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $shipmentDocuments);
        $this->assertSame(GeneratedDocumentType::DeliveryNote, $shipmentDocuments[0]->type);
        $this->assertSame(GeneratedDocumentType::PackingList, $shipmentDocuments[1]->type);

        foreach ($shipmentDocuments as $shipmentDocument) {
            $this->assertSame(GeneratedDocumentStatus::Archived, $shipmentDocument->status);
            $this->assertTrue(Storage::disk('generated_documents')->exists($shipmentDocument->path));
            $this->assertSame(
                hash('sha256', Storage::disk('generated_documents')->get($shipmentDocument->path)),
                $shipmentDocument->sha256,
            );
        }

        $this->assertSame(3, $guard->validatedForItem($item));
        $this->assertSame(0, $guard->shippedForItem($item));
        $this->assertSame(2, $guard->availableForItem($item));

        // 9. Départ : les quantités validées deviennent expédiées sans double
        // comptage ; les archives restent disponibles.
        $shipped = $shipments->ship(
            $validated,
            $this->admin,
            'Départ intégration Lot 5.',
        );

        $this->assertSame(ShipmentStatus::Shipped, $shipped->status);
        $this->assertNotNull($shipped->actual_ship_at);
        $this->assertSame($this->admin->getKey(), $shipped->shipped_by);
        $this->assertSame(3, (int) $shipped->lines()->sum('quantity_shipped'));
        $this->assertSame(0, $guard->validatedForItem($item));
        $this->assertSame(3, $guard->shippedForItem($item));
        $this->assertSame(2, $guard->availableForItem($item));

        foreach ($shipmentDocuments as $shipmentDocument) {
            $this->assertSame(GeneratedDocumentStatus::Archived, $shipmentDocument->fresh()->status);
            $this->assertTrue(Storage::disk('generated_documents')->exists($shipmentDocument->path));
        }

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipped->getKey(),
            'event' => 'validated',
        ]);
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipped->getKey(),
            'event' => 'shipped',
            'comment' => 'Départ intégration Lot 5.',
        ]);
        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'document_review_decided',
        ]);
    }
}
