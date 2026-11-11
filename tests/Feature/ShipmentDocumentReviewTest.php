<?php

namespace Tests\Feature;

use App\Enums\ShipmentDocumentReviewDecision;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\GeneratedDocument;
use App\Models\Shipment;
use App\Models\ShipmentDocumentReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Concerns\BuildsDocumentReviewScenarios;
use Tests\Feature\Concerns\BuildsPreparedShipment;
use Tests\TestCase;

class ShipmentDocumentReviewTest extends TestCase
{
    use BuildsDocumentReviewScenarios;
    use BuildsPreparedShipment;
    use RefreshDatabase;

    protected User $admin;

    protected User $quality;

    protected User $workshop;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');
        Storage::fake('documents');

        $this->admin = User::factory()->admin()->create();
        $this->quality = User::factory()->qualityManager()->create();
        $this->workshop = User::factory()->workshopSupervisor()->create();
    }

    public function test_applicable_document_without_release_snapshot_requires_review(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $requirements = app(ShipmentDocumentService::class)->requirements($workOrder);

        $this->assertCount(1, $requirements);
        $this->assertSame($version->getKey(), $requirements->first()->version->getKey());
        $this->assertNull($requirements->first()->baseline);
        $this->assertNull($requirements->first()->decision);

        $this->expectException(ValidationException::class);
        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);
    }

    public function test_new_applicable_document_after_release_requires_review(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();

        $released = $this->applicableDocument($workOrder, 'DOC-REL-001');
        $releasedVersion = $this->approvedVersion($released, 'A', 1);
        $this->releaseSnapshot($workOrder, $released, $releasedVersion);

        $newDocument = $this->applicableDocument($workOrder, 'DOC-NEW-001');
        $this->approvedVersion($newDocument, 'A', 1);

        $requirements = app(ShipmentDocumentService::class)->requirements($workOrder);

        $this->assertCount(1, $requirements);
        $this->assertSame($newDocument->getKey(), $requirements->first()->document->getKey());
        $this->assertNull($requirements->first()->baseline);

        $this->expectException(ValidationException::class);
        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);
    }

    public function test_new_approved_version_after_release_requires_review(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $versionA = $this->approvedVersion($document, 'A', 1);
        $this->releaseSnapshot($workOrder, $document, $versionA);
        $versionB = $this->approvedVersion($document, 'B', 2);

        $requirements = app(ShipmentDocumentService::class)->requirements($workOrder);

        $this->assertCount(1, $requirements);
        $this->assertSame($versionB->getKey(), $requirements->first()->version->getKey());
        $this->assertSame($versionA->getKey(), $requirements->first()->baseline?->document_version_id);

        $this->expectException(ValidationException::class);
        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);
    }

    public function test_examined_version_then_new_version_requires_a_new_decision(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $versionA = $this->approvedVersion($document, 'A', 1);
        $this->releaseSnapshot($workOrder, $document, $versionA);
        $versionB = $this->approvedVersion($document, 'B', 2);

        $this->decide($workOrder, $document, $versionB, ShipmentDocumentReviewDecision::NoImpact);

        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);

        $versionC = $this->approvedVersion($document, 'C', 3);

        $requirements = app(ShipmentDocumentService::class)->requirements($workOrder);

        $this->assertCount(1, $requirements);
        $this->assertSame($versionC->getKey(), $requirements->first()->version->getKey());
        $this->assertNull($requirements->first()->decision);

        $this->expectException(ValidationException::class);
        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);
    }

    public function test_no_impact_decision_allows_validation(): void
    {
        $this->assertDecisionAllowsValidation(ShipmentDocumentReviewDecision::NoImpact);
    }

    public function test_reviewed_decision_allows_validation(): void
    {
        $this->assertDecisionAllowsValidation(ShipmentDocumentReviewDecision::Reviewed);
    }

    public function test_blocked_decision_blocks_validation(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $document = $this->applicableDocument($shipment->workOrder);
        $version = $this->approvedVersion($document);

        $this->decide(
            $shipment->workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::Blocked,
            'Le plan contredit la fabrication.',
            $shipment,
        );

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('Une décision bloquante aurait dû empêcher la validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
        $this->assertSame(0, GeneratedDocument::query()->count());
    }

    public function test_missing_decision_blocks_validation(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $document = $this->applicableDocument($shipment->workOrder);
        $this->approvedVersion($document);

        try {
            app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);
            $this->fail('Une révision non examinée aurait dû empêcher la validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);
    }

    public function test_departure_is_blocked_by_a_new_unreviewed_revision(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $document = $this->applicableDocument($validated->workOrder);
        $this->approvedVersion($document);

        try {
            app(ShipmentService::class)->ship($validated->fresh(), $this->admin);
            $this->fail('Le départ aurait dû être bloqué par la nouvelle révision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Validated, $validated->fresh()->status);
        $this->assertSame(0, $validated->lines()->sum('quantity_shipped'));
    }

    public function test_departure_is_blocked_by_a_blocked_decision(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $document = $this->applicableDocument($validated->workOrder);
        $version = $this->approvedVersion($document);
        $this->decide(
            $validated->workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::Blocked,
            'Révision bloquante pour le départ.',
            $validated,
        );

        try {
            app(ShipmentService::class)->ship($validated->fresh(), $this->admin);
            $this->fail('Le départ aurait dû être bloqué.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $this->assertSame(ShipmentStatus::Validated, $validated->fresh()->status);
    }

    public function test_quality_manager_can_decide_but_admin_and_workshop_cannot(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $this->assertTrue($this->quality->can('decideDocumentReview', $workOrder));
        $this->assertFalse($this->admin->can('decideDocumentReview', $workOrder));
        $this->assertFalse($this->workshop->can('decideDocumentReview', $workOrder));

        try {
            app(ShipmentDocumentService::class)->decide(
                $workOrder,
                $document,
                $version,
                ShipmentDocumentReviewDecision::NoImpact,
                $this->admin,
                'Tentative administrateur.',
            );
            $this->fail('L’administrateur ne doit pas pouvoir décider P7.');
        } catch (AuthorizationException) {
            // attendu
        }

        $this->expectException(AuthorizationException::class);
        app(ShipmentDocumentService::class)->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::NoImpact,
            $this->workshop,
            'Tentative atelier.',
        );
    }

    public function test_decision_requires_a_comment(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $this->expectException(ValidationException::class);

        $this->decide($workOrder, $document, $version, ShipmentDocumentReviewDecision::NoImpact, '  ');
    }

    public function test_decision_history_is_append_only(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $first = $this->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::NoImpact,
            'Première décision.',
        );

        $second = $this->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::Reviewed,
            'Seconde décision.',
        );

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, ShipmentDocumentReview::query()->count());
        $this->assertSame(ShipmentDocumentReviewDecision::Reviewed, $second->fresh()->decision);

        try {
            $first->decision = ShipmentDocumentReviewDecision::Blocked;
            $first->save();
            $this->fail('Une décision ne doit pas être modifiable.');
        } catch (RuntimeException) {
            // attendu
        }

        $this->expectException(RuntimeException::class);
        $first->delete();
    }

    public function test_blocked_decision_can_be_lifted_by_a_later_decision(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $this->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::Blocked,
            'Bloquée.',
        );

        $this->expectException(ValidationException::class);
        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);
    }

    public function test_blocked_decision_can_be_overridden_by_a_new_decision(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $this->decide($workOrder, $document, $version, ShipmentDocumentReviewDecision::Blocked, 'Bloquée.');
        $this->decide($workOrder, $document, $version, ShipmentDocumentReviewDecision::Reviewed, 'Levé après vérification.');

        app(ShipmentDocumentService::class)->assertDocumentSituationDecided($workOrder);

        $this->assertSame(2, ShipmentDocumentReview::query()->count());
    }

    public function test_decision_records_work_order_and_shipment_audit(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $shipment = app(ShipmentService::class)->create($workOrder, $this->admin);
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $this->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::Reviewed,
            'Revue avec audit.',
            $shipment,
        );

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => 'document_review_decided',
        ]);

        $this->assertDatabaseHas('shipment_audit_logs', [
            'shipment_id' => $shipment->getKey(),
            'event' => 'document_review_decided',
            'comment' => 'Revue avec audit.',
        ]);
    }

    public function test_quality_manager_can_download_an_applicable_document_version(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        Storage::disk('documents')->put($version->storage_path, '%PDF-1.4');

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            $this->actingAs($this->quality)
                ->get("/workshop/quality/documents/versions/{$version->getKey()}/download")
                ->assertOk();

            $this->actingAs($this->workshop)
                ->get("/workshop/quality/documents/versions/{$version->getKey()}/download")
                ->assertForbidden();

            $this->actingAs(User::factory()->create())
                ->get("/workshop/quality/documents/versions/{$version->getKey()}/download")
                ->assertForbidden();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    protected function assertDecisionAllowsValidation(ShipmentDocumentReviewDecision $decision): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $document = $this->applicableDocument($shipment->workOrder);
        $version = $this->approvedVersion($document);

        $this->decide($shipment->workOrder, $document, $version, $decision, 'Décision de test.');

        $validated = app(ShipmentValidationService::class)->validate($shipment->fresh(), $this->admin);

        $this->assertSame(ShipmentStatus::Validated, $validated->status);
    }

    protected function decide(
        WorkOrder $workOrder,
        Document $document,
        DocumentVersion $version,
        ShipmentDocumentReviewDecision $decision,
        string $comment = 'Décision de test.',
        ?Shipment $shipment = null,
    ): ShipmentDocumentReview {
        return app(ShipmentDocumentService::class)->decide(
            $workOrder,
            $document,
            $version,
            $decision,
            $this->quality,
            $comment,
            $shipment,
        );
    }
}
