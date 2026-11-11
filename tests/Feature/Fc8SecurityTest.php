<?php

namespace Tests\Feature;

use App\Enums\ShipmentDocumentReviewDecision;
use App\Enums\WorkOrderStatus;
use App\Models\GeneratedDocument;
use App\Models\ShipmentDocumentReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentPackagingService;
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

/**
 * Vérifications transversales des accès FC-8.
 *
 * Les rôles atelier et qualité conservent l'accès global défini par D-6 ;
 * les profils sans habilitation et les invités ne doivent jamais atteindre
 * les expéditions ni les documents privés.
 */
class Fc8SecurityTest extends TestCase
{
    use BuildsDocumentReviewScenarios;
    use BuildsPreparedShipment;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');
        Storage::fake('shipment_audit');

        $this->admin = User::factory()->admin()->create();
    }

    public function test_shipment_and_generated_document_access_matrix(): void
    {
        $workshop = User::factory()->workshopSupervisor()->create();
        $quality = User::factory()->qualityManager()->create();
        $operator = User::factory()->create();

        [, , $shipment] = $this->validationReadyShipment(5, 3);
        $validated = app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $document = GeneratedDocument::query()
            ->where('shipment_id', $validated->getKey())
            ->where('type', 'delivery_note')
            ->sole();

        $viewUrl = route('generated-documents.view', $document);
        $downloadUrl = route('generated-documents.download', $document);

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            $this->actingAs($workshop)
                ->get("/workshop/shipments/{$validated->getKey()}")
                ->assertOk();
            $this->actingAs($workshop)->get($viewUrl)->assertOk();
            $this->actingAs($workshop)->get($downloadUrl)->assertOk();

            $this->actingAs($quality)
                ->get("/workshop/shipments/{$validated->getKey()}")
                ->assertOk();
            $this->actingAs($quality)->get($viewUrl)->assertOk();
            $this->actingAs($quality)->get($downloadUrl)->assertOk();

            $this->actingAs($operator)
                ->get("/workshop/shipments/{$validated->getKey()}")
                ->assertForbidden();
            $this->actingAs($operator)->get($viewUrl)->assertForbidden();
            $this->actingAs($operator)->get($downloadUrl)->assertForbidden();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }

        $this->actingAs($this->admin)->get($viewUrl)->assertOk();
        $this->actingAs($this->admin)->get($downloadUrl)->assertOk();
    }

    public function test_guest_is_redirected_from_private_document_routes(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $generated = GeneratedDocument::query()
            ->where('shipment_id', $shipment->getKey())
            ->firstOrFail();

        Storage::fake('documents');
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $version = $this->approvedVersion($this->applicableDocument($workOrder));
        Storage::disk('documents')->put($version->storage_path, '%PDF-1.4');

        $this->get(route('generated-documents.view', $generated))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->get(route('documents.versions.download', $version))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_technical_document_version_access_matrix(): void
    {
        Storage::fake('documents');

        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        Storage::disk('documents')->put($version->storage_path, '%PDF-1.4');

        $workshop = User::factory()->workshopSupervisor()->create();
        $quality = User::factory()->qualityManager()->create();
        $operator = User::factory()->create();

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        try {
            $this->actingAs($this->admin)
                ->get(route('documents.versions.download', $version))
                ->assertOk();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }

        $workshopUrl = "/workshop/quality/documents/versions/{$version->getKey()}/download";

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            $this->actingAs($quality)->get($workshopUrl)->assertOk();
            $this->actingAs($workshop)->get($workshopUrl)->assertForbidden();
            $this->actingAs($operator)->get($workshopUrl)->assertForbidden();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    public function test_p7_decision_refuses_a_document_outside_the_work_order_requirements(): void
    {
        $quality = User::factory()->qualityManager()->create();

        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $document = $this->applicableDocument($workOrder);
        $version = $this->approvedVersion($document);

        $foreignWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();
        $foreignDocument = $this->applicableDocument($foreignWorkOrder, 'DOC-FOREIGN-001');
        $foreignVersion = $this->approvedVersion($foreignDocument);

        try {
            app(ShipmentDocumentService::class)->decide(
                $workOrder,
                $foreignDocument,
                $foreignVersion,
                ShipmentDocumentReviewDecision::NoImpact,
                $quality,
                'Tentative sur un document non applicable.',
            );

            $this->fail('Un document hors périmètre aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }

        $this->assertSame(0, ShipmentDocumentReview::query()->count());

        $this->expectException(AuthorizationException::class);

        app(ShipmentDocumentService::class)->decide(
            $workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::NoImpact,
            $this->admin,
            'Tentative administrateur.',
        );
    }

    public function test_archived_generated_document_cannot_be_deleted(): void
    {
        [, , $shipment] = $this->validationReadyShipment(5, 3);
        app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $document = GeneratedDocument::query()
            ->where('shipment_id', $shipment->getKey())
            ->firstOrFail();

        $this->expectException(RuntimeException::class);

        $document->delete();
    }

    public function test_operator_cannot_execute_shipment_services_directly(): void
    {
        $operator = User::factory()->create();

        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $draft = app(ShipmentService::class)->create($workOrder, $this->admin);

        $this->assertAuthorizationDenied(
            fn () => app(ShipmentService::class)->create($workOrder, $operator),
        );
        $this->assertAuthorizationDenied(
            fn () => app(ShipmentService::class)->prepare($draft, $operator),
        );
        $this->assertAuthorizationDenied(
            fn () => app(ShipmentPackagingService::class)->addPackage($draft, [], $operator),
        );

        [, , $prepared] = $this->validationReadyShipment(5, 3);

        $this->assertAuthorizationDenied(
            fn () => app(ShipmentValidationService::class)->validate($prepared, $operator),
        );

        $validated = app(ShipmentValidationService::class)->validate($prepared, $this->admin);

        $this->assertAuthorizationDenied(
            fn () => app(ShipmentService::class)->ship($validated, $operator),
        );
        $this->assertAuthorizationDenied(
            fn () => app(ShipmentService::class)->cancel(
                $validated,
                $operator,
                'Motif suffisamment long pour un test d’autorisation.',
            ),
        );

        $this->assertSame('validated', $validated->fresh()->status->value);
    }

    protected function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Une AuthorizationException était attendue.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
