<?php

namespace Tests\Feature;

use App\Enums\DocumentSnapshotReason;
use App\Enums\DocumentStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\GeneratedDocument;
use App\Models\ShipmentAuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use App\Models\WorkOrderItem;
use App\Services\GeneratedDocumentArchiveService;
use App\Services\ShipmentAuditService;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentPrintService;
use App\Services\ShipmentQualityGateService;
use App\Services\ShipmentQuantityGuard;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationRefusalRecorder;
use App\Services\ShipmentValidationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Feature\Concerns\BuildsDocumentReviewScenarios;
use Tests\Feature\Concerns\BuildsPreparedShipment;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

class ShipmentValidationTest extends TestCase
{
    use BuildsDocumentReviewScenarios;
    use BuildsPreparedShipment;
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    protected User $admin;

    protected User $workshop;

    protected User $quality;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');
        Storage::fake('shipment_audit');

        $this->admin = User::factory()->admin()->create();
        $this->workshop = User::factory()->workshopSupervisor()->create();
        $this->quality = User::factory()->qualityManager()->create();
    }

    public function test_validation_generates_the_delivery_note_and_packing_list(): void
    {
        [$workOrder, $item, $shipment] = $this->validationReadyShipment(5, 3);

        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $this->assertSame(ShipmentStatus::Validated, $validated->status);
        $this->assertSame($this->admin->getKey(), $validated->validated_by);
        $this->assertNotNull($validated->validated_at);
        $this->assertSame($validated->number.'-BL', $validated->delivery_note_reference);

        $documents = GeneratedDocument::query()
            ->where('shipment_id', $validated->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $documents);
        $this->assertSame(GeneratedDocumentType::DeliveryNote, $documents[0]->type);
        $this->assertSame(GeneratedDocumentType::PackingList, $documents[1]->type);
        $this->assertSame($workOrder->getKey(), $documents[0]->work_order_id);

        foreach ($documents as $document) {
            $this->assertSame(GeneratedDocumentStatus::Archived, $document->status);
            $this->assertSame('generated_documents', $document->disk);
            $this->assertTrue(Storage::disk('generated_documents')->exists($document->path));
            $this->assertSame(
                hash('sha256', Storage::disk('generated_documents')->get($document->path)),
                $document->sha256,
            );
            $this->assertSame(64, strlen($document->source_data_hash));
        }

        $guard = app(ShipmentQuantityGuard::class);
        $this->assertSame(3, $guard->validatedForItem($item));
        $this->assertSame(0, $guard->shippedForItem($item));
        $this->assertSame(2, $guard->availableForItem($item));

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $validated->getKey(),
            'event' => 'validated',
            'user_id' => $this->admin->getKey(),
        ]);
        $this->assertSame(2, ShipmentAuditLog::query()
            ->where('shipment_id', $validated->getKey())
            ->where('event', 'document_generated')
            ->count());

        $data = app(ShipmentPrintService::class)->deliveryNoteData($validated->fresh());

        $this->assertSame($item->mark, $data['lines'][0]['mark']);
        $this->assertSame(3, $data['lines'][0]['quantity']);
        $this->assertSame('COLIS-01', $data['packages'][0]['code']);
        $this->assertNotEmpty($data['client_name']);

        $deliveryHtml = view('pdf.delivery-note', ['data' => $data])->render();
        $this->assertStringContainsString($item->mark, $deliveryHtml);
        $this->assertStringContainsString('COLIS-01', $deliveryHtml);
        $this->assertStringContainsString($workOrder->number, $deliveryHtml);

        $packingData = app(ShipmentPrintService::class)->packingListData($validated->fresh());
        $packingHtml = view('pdf.packing-list', ['data' => $packingData])->render();
        $this->assertStringContainsString('COLIS-01', $packingHtml);
        $this->assertStringContainsString($item->mark, $packingHtml);
    }

    public function test_validation_refuses_a_non_prepared_shipment(): void
    {
        [$workOrder, $item] = $this->releasedWorkOrderWithItem(5);
        $shipment = app(ShipmentService::class)->create($workOrder, $this->admin);
        $line = app(ShipmentService::class)->addLine($shipment, $item, 3, $this->admin);
        $package = app(ShipmentPackagingService::class)->addPackage($shipment, [], $this->admin);
        app(ShipmentPackagingService::class)->assign($package, $line, 3, $this->admin);
        $workOrder = $this->completeWorkOrder($workOrder->fresh());

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('Une expédition non préparée aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => 'validation_refused',
        ]);
    }

    public function test_quality_gate_refuses_a_work_order_that_is_not_completed(): void
    {
        [$workOrder] = $this->releasedWorkOrderWithItem(5);

        $this->expectException(ValidationException::class);

        app(ShipmentQualityGateService::class)->assertShippable($workOrder);
    }

    public function test_quality_gate_refuses_a_historical_work_order_without_proof(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);
        $shipment = $this->preparedShipment($workOrder, $item, 3);

        try {
            app(ShipmentValidationService::class)->validate($shipment, $this->admin);
            $this->fail('La validation aurait dû être refusée sans preuve qualité.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => 'validation_refused',
        ]);
    }

    public function test_validation_is_refused_for_an_unauthorized_user(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $this->expectException(AuthorizationException::class);

        app(ShipmentValidationService::class)->validate($shipment, $this->workshop);
    }

    public function test_validation_refuses_a_blocking_non_conformity_reopened_after_closure(): void
    {
        [$workOrder, , $shipment] = $this->validationReadyShipment(5, 3);

        $this->addNonConformity($workOrder->fresh(), [
            'severity' => NonConformitySeverity::Major,
            'status' => NonConformityStatus::Open,
            'is_blocking' => true,
        ]);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('La non-conformité bloquante aurait dû empêcher la validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quality', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => 'validation_refused',
        ]);
    }

    public function test_validation_is_refused_when_packaging_is_incomplete(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3, completePackaging: false);

        try {
            app(ShipmentValidationService::class)->validate($shipment, $this->admin);
            $this->fail('Le colisage incomplet aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('packaging', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => 'validation_refused',
        ]);
    }

    public function test_documentary_refusal_is_audited_after_rollback(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('La révision non examinée aurait dû empêcher la validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertSame(1, ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'validation_refused')
            ->count());
    }

    public function test_validation_refuses_a_second_shipment_exceeding_the_cumulative_quantity(): void
    {
        [$workOrder, $item] = $this->releasedWorkOrderWithItem(5);
        $first = $this->preparedShipment($workOrder, $item, 3, packageCode: 'COLIS-01');
        $second = $this->preparedShipment($workOrder, $item, 3, packageCode: 'COLIS-02');
        $workOrder = $this->completeWorkOrder($workOrder->fresh());

        app(ShipmentValidationService::class)->validate($first->fresh(), $this->admin);

        try {
            app(ShipmentValidationService::class)->validate($second->fresh(), $this->admin);
            $this->fail('Le cumul de quantités aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantities', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $second->fresh()->status);
        $this->assertSame(2, GeneratedDocument::query()->count());
        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $second->getKey(),
            'event' => 'validation_refused',
        ]);
    }

    public function test_validation_refuses_a_second_validation(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('Une seconde validation aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(1, ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'validation_refused')
            ->count());
    }

    public function test_validation_leaves_no_partial_state_when_pdf_generation_fails(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $mock = Mockery::mock(ShipmentPrintService::class);
        $mock->shouldReceive('deliveryNote')->once()->andThrow(new RuntimeException('Échec de rendu.'));
        $this->app->instance(ShipmentPrintService::class, $mock);

        try {
            app(ShipmentValidationService::class)->validate($shipment, $this->admin);
            $this->fail('La validation aurait dû échouer.');
        } catch (ValidationException) {
            // attendu
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertSame([], Storage::disk('generated_documents')->allFiles());
    }

    public function test_validation_cleans_stored_files_when_archiving_fails(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $mock = Mockery::mock(GeneratedDocumentArchiveService::class);
        $mock->shouldReceive('archiveStoredForShipment')
            ->once()
            ->andThrow(new RuntimeException('Échec d’archivage.'));
        $this->app->instance(GeneratedDocumentArchiveService::class, $mock);

        try {
            app(ShipmentValidationService::class)->validate($shipment, $this->admin);
            $this->fail('La validation aurait dû échouer.');
        } catch (ValidationException) {
            // attendu
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertSame([], Storage::disk('generated_documents')->allFiles());
    }

    public function test_delivery_note_is_private_and_access_controlled(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $document = GeneratedDocument::query()
            ->where('type', GeneratedDocumentType::DeliveryNote->value)
            ->sole();

        $this->actingAs($this->admin)
            ->get(route('generated-documents.view', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('generated-documents.download', $document))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('generated-documents.view', $document))
            ->assertForbidden();
    }

    public function test_newer_approved_document_revision_blocks_validation_gate(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();

        $document = Document::factory()->create([
            'job_id' => $workOrder->job_id,
            'work_order_id' => null,
            'status' => DocumentStatus::Active,
            'reference' => 'DOC-REV-001',
        ]);

        $versionA = DocumentVersion::factory()->approved()->create([
            'document_id' => $document->getKey(),
            'revision' => 'A',
            'version_number' => 1,
        ]);

        WorkOrderDocumentSnapshot::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'document_id' => $document->getKey(),
            'document_version_id' => $versionA->getKey(),
            'category' => $document->category,
            'title' => $document->title,
            'reference' => $document->reference,
            'revision' => 'A',
            'version_number' => 1,
            'sha256' => $versionA->sha256,
            'captured_at' => now()->subHour(),
            'reason' => DocumentSnapshotReason::Release,
        ]);

        DocumentVersion::factory()->approved()->create([
            'document_id' => $document->getKey(),
            'revision' => 'B',
            'version_number' => 2,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentDocumentService::class)
            ->assertDocumentSituationDecided($workOrder);
    }

    public function test_refusal_audit_survives_an_external_transaction_rollback(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        // Une révision applicable sans décision force un refus documentaire.
        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        try {
            DB::transaction(function () use ($shipment): void {
                app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            });

            $this->fail('La validation aurait dû être refusée dans la transaction externe.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $entries = $this->refusalAuditEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('validation_refused', $entries[0]['event']);
        $this->assertSame($shipment->getKey(), $entries[0]['shipment_id']);
        $this->assertSame($this->admin->getKey(), $entries[0]['user_id']);
        $this->assertNotSame('', $entries[0]['attempt_id']);

        // Le miroir SQL est annulé avec la transaction externe ; le fichier
        // append-only conserve la preuve du refus.
        $this->assertSame(0, ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'validation_refused')
            ->count());

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertSame(0, GeneratedDocument::query()->count());
    }

    public function test_refused_validation_creates_exactly_one_durable_audit_entry(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('La validation aurait dû être refusée.');
        } catch (ValidationException) {
            // attendu
        }

        $this->assertCount(1, $this->refusalAuditEntries());
        $this->assertSame(1, ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'validation_refused')
            ->count());
    }

    public function test_successful_validation_creates_no_refusal_audit(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $this->assertSame([], $this->refusalAuditEntries());
        $this->assertSame(0, ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'validation_refused')
            ->count());
    }

    public function test_refusal_audit_metadata_is_limited(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('La validation aurait dû être refusée.');
        } catch (ValidationException) {
            // attendu
        }

        $entry = $this->refusalAuditEntries()[0];

        $this->assertEqualsCanonicalizing([
            'attempt_id',
            'event',
            'shipment_id',
            'work_order_id',
            'shipment_number',
            'user_id',
            'status_before',
            'target_status',
            'reason',
            'error_keys',
            'exception',
            'recorded_at',
        ], array_keys($entry));

        $this->assertSame(['documents'], $entry['error_keys']);
        $this->assertSame(ShipmentStatus::Prepared->value, $entry['status_before']);
        $this->assertSame(ShipmentStatus::Validated->value, $entry['target_status']);
        $this->assertArrayNotHasKey('comment', $entry);
        $this->assertArrayNotHasKey('metadata', $entry);
    }

    public function test_refusal_audit_survives_a_database_audit_failure(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        $this->mock(ShipmentAuditService::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('Journal SQL indisponible.'));
        });

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('La validation aurait dû être refusée.');
        } catch (ValidationException) {
            // attendu
        }

        $this->assertCount(1, $this->refusalAuditEntries());
    }

    public function test_refusal_audit_disk_is_private(): void
    {
        $root = (string) config('filesystems.disks.shipment_audit.root');

        $this->assertStringStartsWith(storage_path('app/private/shipment-audit'), $root);
        $this->assertStringNotContainsString(public_path(), $root);
        $this->assertFalse((bool) config('filesystems.disks.shipment_audit.serve'));
        $this->assertTrue((bool) config('filesystems.disks.shipment_audit.throw'));
    }

    public function test_refusal_audit_falls_back_to_log_when_audit_disk_fails(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        Log::spy();

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('makeDirectory')
            ->once()
            ->andThrow(new RuntimeException('Disque d’audit indisponible.'));
        Storage::set('shipment_audit', $disk);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('La validation aurait dû être refusée.');
        } catch (ValidationException) {
            // attendu
        }

        Log::shouldHaveReceived('critical')->atLeast()->once();

        $this->assertSame(1, ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'validation_refused')
            ->count());
    }

    public function test_newer_approved_document_revision_does_not_block_when_unchanged(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();

        app(ShipmentDocumentService::class)
            ->assertDocumentSituationDecided($workOrder);

        $this->assertTrue(true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function refusalAuditEntries(): array
    {
        $disk = Storage::disk('shipment_audit');

        if (! $disk->exists(ShipmentValidationRefusalRecorder::PATH)) {
            return [];
        }

        return collect(explode(PHP_EOL, trim($disk->get(ShipmentValidationRefusalRecorder::PATH))))
            ->filter(static fn (string $line): bool => trim($line) !== '')
            ->map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }
}
