<?php

namespace Tests\Feature;

use App\Enums\ShipmentDocumentReviewDecision;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Shipments\Pages\CreateShipment;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentAuditLogsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentDocumentReviewsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentGeneratedDocumentsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentLinesRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentPackagesRelationManager;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\GeneratedDocument;
use App\Models\Shipment;
use App\Models\ShipmentAuditLog;
use App\Models\ShipmentDocumentReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsDocumentReviewScenarios;
use Tests\Feature\Concerns\BuildsPreparedShipment;
use Tests\TestCase;

class ShipmentFilamentTest extends TestCase
{
    use BuildsDocumentReviewScenarios;
    use BuildsPreparedShipment;
    use RefreshDatabase;

    public function test_admin_can_create_a_shipment_from_the_filament_form(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $this->actingAs($admin);

        Livewire::test(CreateShipment::class)
            ->fillForm([
                'work_order_id' => $workOrder->getKey(),
                'carrier_name' => 'Transporteur Filament',
                'delivery_address' => '1 rue du Test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $shipment = Shipment::query()->sole();

        $this->assertSame($workOrder->getKey(), $shipment->work_order_id);
        $this->assertSame('Transporteur Filament', $shipment->carrier_name);
        $this->assertSame(ShipmentStatus::Draft, $shipment->status);

        $this->get(ShipmentResource::getUrl('view', ['record' => $shipment]))
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertSee('Lignes de préparation')
            ->assertSee('Colis et répartition')
            ->assertSee('Journal d\'expédition');
    }

    public function test_workshop_supervisor_can_access_the_shipment_resource_in_the_workshop_panel(): void
    {
        $workshop = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            $this->actingAs($workshop)
                ->get('/workshop/shipments')
                ->assertOk();

            $this->actingAs($workshop)
                ->get('/workshop/shipments/create')
                ->assertOk();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    public function test_quality_manager_can_view_but_not_create_a_shipment(): void
    {
        $quality = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $shipment = app(ShipmentService::class)->create($workOrder, User::factory()->admin()->create());

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            $this->actingAs($quality)
                ->get('/workshop/shipments')
                ->assertOk();

            $this->actingAs($quality)
                ->get("/workshop/shipments/{$shipment->getKey()}")
                ->assertOk();

            $this->actingAs($quality)
                ->get('/workshop/shipments/create')
                ->assertForbidden();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    public function test_operator_cannot_access_shipments(): void
    {
        $operator = User::factory()->create();

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            $this->actingAs($operator)
                ->get('/workshop/shipments')
                ->assertForbidden();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    public function test_view_page_exposes_preparation_actions_but_no_validation_or_shipping_action(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $shipment = app(ShipmentService::class)->create($workOrder, $admin);

        $this->actingAs($admin);

        Livewire::test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
            ->assertActionExists('editHeader')
            ->assertActionExists('prepare')
            ->assertActionHidden('validate')
            ->assertActionHidden('ship')
            ->assertActionExists('cancel');
    }

    public function test_admin_can_prepare_then_reopen_a_shipment_from_the_view_page(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $shipment = app(ShipmentService::class)->create($workOrder, $admin);

        $this->actingAs($admin);

        Livewire::test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
            ->callAction('prepare')
            ->assertHasNoActionErrors();

        $this->assertSame(ShipmentStatus::Prepared, $shipment->fresh()->status);

        Livewire::test(ViewShipment::class, ['record' => $shipment->fresh()->getRouteKey()])
            ->callAction('reopen')
            ->assertHasNoActionErrors();

        $this->assertSame(ShipmentStatus::Draft, $shipment->fresh()->status);
    }

    public function test_view_page_shows_existing_lines_and_packages(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'REP-777',
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);
        $shipment = app(ShipmentService::class)->create($workOrder, $admin);
        $line = app(ShipmentService::class)->addLine($shipment, $item, 3, $admin);
        $package = app(ShipmentPackagingService::class)->addPackage($shipment, ['code' => 'COLIS-77'], $admin);

        $this->actingAs($admin)
            ->get(ShipmentResource::getUrl('view', ['record' => $shipment]))
            ->assertOk()
            ->assertSee('REP-777');

        Livewire::test(ShipmentLinesRelationManager::class, [
            'ownerRecord' => $shipment->fresh(),
            'pageClass' => ViewShipment::class,
        ])->assertCanSeeTableRecords([$line]);

        Livewire::test(ShipmentPackagesRelationManager::class, [
            'ownerRecord' => $shipment->fresh(),
            'pageClass' => ViewShipment::class,
        ])->assertCanSeeTableRecords([$package]);
    }

    public function test_quantities_by_mark_section_escapes_line_snapshots(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => '<img src=x onerror=alert(1)>',
            'designation' => '<script>alert("xss")</script>',
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
        ]);
        $shipment = app(ShipmentService::class)->create($workOrder, $admin);
        app(ShipmentService::class)->addLine($shipment, $item, 3, $admin);

        $response = $this->actingAs($admin)
            ->get(ShipmentResource::getUrl('view', ['record' => $shipment]));

        $response->assertOk();
        $response->assertSee(e($item->mark), false);
        $response->assertSee(e($item->designation), false);
        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
        $response->assertDontSee('<script>alert("xss")</script>', false);
    }

    public function test_admin_can_validate_then_ship_from_the_view_page(): void
    {
        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $this->actingAs($this->admin);

        Livewire::test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
            ->assertActionExists('validate')
            ->assertActionExists('cancel')
            ->assertActionHidden('ship')
            ->callAction('validate')
            ->assertHasNoActionErrors();

        $this->assertSame(ShipmentStatus::Validated, $shipment->fresh()->status);

        Livewire::test(ViewShipment::class, ['record' => $shipment->fresh()->getRouteKey()])
            ->assertActionExists('validate')
            ->assertActionExists('ship')
            ->assertActionExists('cancel')
            ->callAction('ship')
            ->assertHasNoActionErrors();

        $this->assertSame(ShipmentStatus::Shipped, $shipment->fresh()->status);
    }

    public function test_workshop_and_quality_do_not_see_validation_actions(): void
    {
        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        $workshop = User::factory()->workshopSupervisor()->create();
        $quality = User::factory()->qualityManager()->create();
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        Livewire::actingAs($workshop)
            ->test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
            ->assertActionHidden('validate')
            ->assertActionHidden('ship')
            ->assertActionHidden('cancel');

        Livewire::actingAs($quality)
            ->test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
            ->assertActionHidden('validate')
            ->assertActionHidden('ship')
            ->assertActionHidden('cancel');
    }

    public function test_validation_exposes_the_archived_documents_on_the_shipment_page(): void
    {
        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        app(ShipmentValidationService::class)->validate($shipment, $this->admin);

        $documents = GeneratedDocument::query()
            ->where('shipment_id', $shipment->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $documents);

        $this->actingAs($this->admin)
            ->get(ShipmentResource::getUrl('view', ['record' => $shipment]))
            ->assertOk()
            ->assertSee(route('generated-documents.view', $documents->first()), false);

        Livewire::test(ShipmentGeneratedDocumentsRelationManager::class, [
            'ownerRecord' => $shipment->fresh(),
            'pageClass' => ViewShipment::class,
        ])->assertCanSeeTableRecords($documents->all());
    }

    public function test_quality_manager_can_decide_p7_from_the_shipment_page(): void
    {
        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        $quality = User::factory()->qualityManager()->create();
        $workshop = User::factory()->workshopSupervisor()->create();
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $version = $this->approvedVersion($document);

        $this->assertCount(1, app(ShipmentDocumentService::class)->requirements($shipment->workOrder));

        $qualityComponent = Livewire::actingAs($quality)
            ->test(ShipmentDocumentReviewsRelationManager::class, [
                'ownerRecord' => $shipment->fresh(),
                'pageClass' => ViewShipment::class,
            ]);

        $qualityAction = collect($qualityComponent->instance()->getTable()->getHeaderActions())
            ->first(fn ($action): bool => $action->getName() === 'decideDocumentReview');

        $this->assertNotNull($qualityAction);
        $this->assertTrue($qualityAction->isVisible());

        $qualityComponent
            ->callTableAction('decideDocumentReview', null, data: [
                'requirement' => $document->getKey().':'.$version->getKey(),
                'decision' => ShipmentDocumentReviewDecision::NoImpact->value,
                'comment' => 'Aucun impact sur la fabrication.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, ShipmentDocumentReview::query()->count());
        $this->assertSame(
            ShipmentDocumentReviewDecision::NoImpact,
            ShipmentDocumentReview::query()->sole()->decision,
        );

        $qualityComponent->assertCanSeeTableRecords(ShipmentDocumentReview::query()->get());

        foreach ([$this->admin, $workshop] as $user) {
            $component = Livewire::actingAs($user)
                ->test(ShipmentDocumentReviewsRelationManager::class, [
                    'ownerRecord' => $shipment->fresh(),
                    'pageClass' => ViewShipment::class,
                ]);

            $action = collect($component->instance()->getTable()->getHeaderActions())
                ->first(fn ($action): bool => $action->getName() === 'decideDocumentReview');

            $this->assertNotNull($action);
            $this->assertFalse($action->isVisible());
        }
    }

    public function test_blocked_p7_decision_is_visually_identifiable(): void
    {
        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        $quality = User::factory()->qualityManager()->create();
        [, , $shipment] = $this->validationReadyShipment(5, 3);

        $document = $this->applicableDocument($shipment->workOrder);
        $version = $this->approvedVersion($document);

        app(ShipmentDocumentService::class)->decide(
            $shipment->workOrder,
            $document,
            $version,
            ShipmentDocumentReviewDecision::Blocked,
            $quality,
            'Blocage visuel de test.',
            $shipment,
        );

        $requirements = app(ShipmentDocumentService::class)->requirements($shipment->workOrder);

        $html = view('filament.shipments.document-review-summary', [
            'requirements' => $requirements,
        ])->render();

        $this->assertStringContainsString('Bloquante', $html);
        $this->assertStringContainsString('bg-danger-100', $html);

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            Livewire::actingAs($quality)
                ->test(ShipmentDocumentReviewsRelationManager::class, [
                    'ownerRecord' => $shipment->fresh(),
                    'pageClass' => ViewShipment::class,
                ])
                ->assertSee('Bloquante');
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    public function test_audit_log_displays_human_readable_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $shipment = app(ShipmentService::class)->create($workOrder, $admin);
        app(ShipmentService::class)->prepare($shipment, $admin);

        $preparedLog = ShipmentAuditLog::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('event', 'prepared')
            ->sole();

        Livewire::actingAs($admin)
            ->test(ShipmentAuditLogsRelationManager::class, [
                'ownerRecord' => $shipment->fresh(),
                'pageClass' => ViewShipment::class,
            ])
            ->assertTableColumnFormattedStateSet('from_status', 'Brouillon', $preparedLog)
            ->assertTableColumnFormattedStateSet('to_status', 'Préparée', $preparedLog);
    }
}
