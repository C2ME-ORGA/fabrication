<?php

namespace Tests\Feature;

use App\Enums\ShipmentDocumentReviewDecision;
use App\Enums\WorkOrderStatus;
use App\Models\GeneratedDocument;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationService;
use App\Services\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsDocumentReviewScenarios;
use Tests\TestCase;

/**
 * SQLite ne fournit pas de verrouillage de ligne concurrent. Ces tests sont
 * ignorés hors MySQL.
 *
 * Une exécution parallèle réelle de deux process n'est pas lancée depuis
 * PHPUnit sous Windows. Le test vérifie le mécanisme de sérialisation :
 * verrou de ligne sur l'OF et sur la séquence, plus les contraintes uniques.
 */
class ShipmentConcurrencyTest extends TestCase
{
    use BuildsDocumentReviewScenarios;
    use RefreshDatabase;

    public function test_shipment_creation_locks_the_work_order_and_sequence_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $shipment = null;

        DB::beginTransaction();

        try {
            $shipment = app(ShipmentService::class)->create($workOrder, $admin);

            $this->assertRowIsLocked('work_orders', $workOrder->getKey());

            $sequenceId = (int) DB::table('shipment_sequences')
                ->where('year', now()->year)
                ->value('id');

            $this->assertGreaterThan(0, $sequenceId);
            $this->assertRowIsLocked('shipment_sequences', $sequenceId);
        } finally {
            DB::rollBack();
        }

        $this->assertNotNull($shipment);
    }

    public function test_shipment_numbers_are_unique_and_sequential_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $firstWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $secondWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $first = app(ShipmentService::class)->create($firstWorkOrder, $admin);
        $second = app(ShipmentService::class)->create($secondWorkOrder, $admin);

        $this->assertMatchesRegularExpression('/^EXP-\d{4}-0001$/', $first->number);
        $this->assertMatchesRegularExpression('/^EXP-\d{4}-0002$/', $second->number);
        $this->assertNotSame($first->number, $second->number);
    }

    public function test_unique_shipment_number_constraint_is_enforced_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $firstWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $secondWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $first = app(ShipmentService::class)->create($firstWorkOrder, $admin);

        $this->expectException(QueryException::class);

        Shipment::query()->create([
            'work_order_id' => $secondWorkOrder->getKey(),
            'number' => $first->number,
            'status' => 'draft',
            'created_by' => $admin->getKey(),
        ]);
    }

    public function test_validation_locks_the_work_order_items_shipment_and_documents_on_mysql(): void
    {
        $this->skipUnlessMysql();

        Storage::fake('generated_documents');

        $admin = User::factory()->admin()->create();
        $quality = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);

        $shipment = app(ShipmentService::class)->create($workOrder, $admin);
        $line = app(ShipmentService::class)->addLine($shipment, $item, 3, $admin);
        $package = app(ShipmentPackagingService::class)->addPackage($shipment, [], $admin);
        app(ShipmentPackagingService::class)->assign($package, $line, 3, $admin);
        $shipment = app(ShipmentService::class)->prepare($shipment, $admin);

        $workOrderService = app(WorkOrderService::class);
        $workOrder = $workOrderService->transitionTo($workOrder, WorkOrderStatus::InProgress, $admin);
        $workOrder = $workOrderService->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $admin);
        $workOrder = $workOrderService->transitionTo($workOrder, WorkOrderStatus::Completed, $admin);

        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        app(ShipmentDocumentService::class)->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::NoImpact,
            $quality,
            'Aucun impact sur la fabrication.',
        );

        $generatedDocuments = collect();

        DB::beginTransaction();

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $admin);

            $this->assertRowIsLocked('work_orders', $workOrder->getKey());
            $this->assertRowIsLocked('work_order_items', $item->getKey());
            $this->assertRowIsLocked('shipments', $shipment->getKey());
            $this->assertRowIsLocked('jobs', $workOrder->job_id);

            // DocumentVersionService::approve() commence par verrouiller cette
            // ligne `documents` : NOWAIT prouve qu'une approbation concurrente
            // serait bloquée jusqu'au commit de la validation.
            $this->assertRowIsLocked('documents', $document->getKey());

            $generatedDocuments = GeneratedDocument::query()
                ->where('shipment_id', $shipment->getKey())
                ->get();
        } finally {
            DB::rollBack();

            foreach ($generatedDocuments as $generated) {
                Storage::disk($generated->disk)->delete($generated->path);
            }
        }
    }

    public function test_departure_locks_applicable_documents_on_mysql(): void
    {
        $this->skipUnlessMysql();

        Storage::fake('generated_documents');

        $admin = User::factory()->admin()->create();
        $quality = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);

        $shipment = app(ShipmentService::class)->create($workOrder, $admin);
        $line = app(ShipmentService::class)->addLine($shipment, $item, 3, $admin);
        $package = app(ShipmentPackagingService::class)->addPackage($shipment, [], $admin);
        app(ShipmentPackagingService::class)->assign($package, $line, 3, $admin);
        $shipment = app(ShipmentService::class)->prepare($shipment, $admin);

        $workOrderService = app(WorkOrderService::class);
        $workOrder = $workOrderService->transitionTo($workOrder, WorkOrderStatus::InProgress, $admin);
        $workOrder = $workOrderService->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $admin);
        $workOrder = $workOrderService->transitionTo($workOrder, WorkOrderStatus::Completed, $admin);

        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        app(ShipmentDocumentService::class)->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::NoImpact,
            $quality,
            'Aucun impact sur la fabrication.',
        );

        $validated = app(ShipmentValidationService::class)->validate($shipment, $admin);

        DB::beginTransaction();

        try {
            app(ShipmentService::class)->ship($validated->fresh(), $admin);

            $this->assertRowIsLocked('work_orders', $workOrder->getKey());
            $this->assertRowIsLocked('shipments', $validated->getKey());
            $this->assertRowIsLocked('jobs', $workOrder->job_id);

            // Même point de sérialisation que l'approbation documentaire.
            $this->assertRowIsLocked('documents', $document->getKey());
        } finally {
            DB::rollBack();
        }
    }

    protected function skipUnlessMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Le verrouillage concurrent MySQL ne peut pas être vérifié sur '
                .DB::connection()->getDriverName().'. Lancez ce test avec une base MySQL dédiée.',
            );
        }
    }

    protected function assertRowIsLocked(string $table, int $id): void
    {
        $connection = config('database.connections.mysql');
        config(['database.connections.mysql_probe' => $connection]);
        DB::purge('mysql_probe');

        try {
            DB::connection('mysql_probe')->statement(
                "select * from {$table} where id = ? for update nowait",
                [$id],
            );

            $this->fail("La ligne {$table}#{$id} aurait dû rester verrouillée par la transaction en cours.");
        } catch (QueryException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            $this->assertContains(
                $driverCode,
                [1205, 3572],
                "Erreur SQL inattendue pendant la vérification du verrou : {$exception->getMessage()}",
            );
        } finally {
            DB::purge('mysql_probe');
        }
    }
}
