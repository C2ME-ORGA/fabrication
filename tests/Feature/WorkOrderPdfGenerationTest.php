<?php

namespace Tests\Feature;

use App\Contracts\WorkOrderQualityReleaseSource;
use App\DTO\WorkOrderQualityReleaseData;
use App\Enums\DocumentSnapshotReason;
use App\Enums\DocumentStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderOperation;
use App\Services\WorkOrderAuditService;
use App\Services\WorkOrderOperationAuditService;
use App\Services\WorkOrderPdfGenerationService;
use App\Services\WorkOrderPrintService;
use App\Services\WorkOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class WorkOrderPdfGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Job $job;

    protected WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');

        $this->admin = User::factory()->admin()->create();
        $this->job = Job::factory()->active()->create();

        $this->workOrder = WorkOrder::factory()->create([
            'job_id' => $this->job->getKey(),
            'status' => WorkOrderStatus::Released,
        ]);

        $this->workOrder->forceFill([
            'be_approved_by' => $this->admin->getKey(),
            'be_approved_at' => now()->subDay(),
            'released_by' => $this->admin->getKey(),
            'released_at' => now()->subHours(2),
        ])->save();

        WorkOrderItem::factory()->forWorkOrder($this->workOrder)->create([
            'mark' => 'R-001',
            'designation' => 'Poteau IPE 200',
            'length_mm' => 6000,
            'quantity_planned' => 10,
            'quantity_produced' => 8,
            'quantity_rejected' => 1,
        ]);
    }

    public function test_generation_archives_a_real_pdf_and_traces_it(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);

        $this->assertStringStartsWith($this->workOrder->number.'-PDF-0001', $document->reference);
        $this->assertSame(GeneratedDocumentStatus::Archived, $document->status);
        $this->assertSame($this->workOrder->getKey(), $document->work_order_id);

        $this->assertTrue(Storage::disk('generated_documents')->exists($document->path));
        $this->assertFalse(Storage::disk('public')->exists($document->path));

        $contents = Storage::disk('generated_documents')->get($document->path);

        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertSame(hash('sha256', $contents), $document->sha256);

        $this->assertDatabaseHas('generated_documents', [
            'id' => $document->getKey(),
            'work_order_id' => $this->workOrder->getKey(),
            'type' => 'work_order_pdf',
            'status' => 'archived',
            'reference' => $document->reference,
        ]);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $this->workOrder->getKey(),
            'event' => 'pdf_generated',
            'user_id' => $this->admin->getKey(),
        ]);

        $data = app(WorkOrderPrintService::class)->build($this->workOrder->fresh(), $this->admin);

        $this->assertCount(1, $data->items);
        $this->assertSame('R-001', $data->items[0]->mark);
        $this->assertSame('Poteau IPE 200', $data->items[0]->designation);
        $this->assertSame('non_effectue', $data->visaSnapshot['atelier']['status']);
        $this->assertSame('non_effectue', $data->visaSnapshot['qualite']['status']);

        $html = view('pdf.work-order', [
            'data' => $data,
            'companyName' => 'C2ME',
        ])->render();

        $this->assertStringContainsString('R-001', $html);
        $this->assertStringContainsString('Poteau IPE 200', $html);
        $this->assertStringContainsString('Non effectué', $html);
    }

    public function test_multiple_generations_do_not_overwrite_previous_files(): void
    {
        $first = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);
        $firstContents = Storage::disk('generated_documents')->get($first->path);

        $second = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder->fresh(), $this->admin);

        $this->assertNotSame($first->path, $second->path);
        $this->assertSame(1, $first->fresh()->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(GeneratedDocumentStatus::Superseded, $first->fresh()->status);
        $this->assertSame(GeneratedDocumentStatus::Archived, $second->status);
        $this->assertTrue(Storage::disk('generated_documents')->exists($first->path));
        $this->assertTrue(Storage::disk('generated_documents')->exists($second->path));
        $this->assertSame($firstContents, Storage::disk('generated_documents')->get($first->path));
    }

    public function test_viewer_route_is_authenticated_and_authorized(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);
        $workshop = User::factory()->workshopSupervisor()->create();
        $operator = User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('generated-documents.view', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('generated-documents.download', $document))
            ->assertOk();

        $this->actingAs($workshop)
            ->get(route('generated-documents.view', $document))
            ->assertOk();

        $this->actingAs($operator)
            ->get(route('generated-documents.view', $document))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_the_filament_login(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);

        $this->get(route('generated-documents.view', $document))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_workshop_supervisor_can_view_but_cannot_generate_or_modify(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);
        $workshop = User::factory()->workshopSupervisor()->create();

        $this->assertTrue(Gate::forUser($workshop)->allows('viewPdf', $this->workOrder));
        $this->assertFalse(Gate::forUser($workshop)->allows('generatePdf', $this->workOrder));
        $this->assertTrue(Gate::forUser($workshop)->allows('view', $document));
        $this->assertFalse(Gate::forUser($workshop)->allows('update', $document));
        $this->assertFalse(Gate::forUser($workshop)->allows('delete', $document));
    }

    public function test_quality_visa_is_not_acquired_without_a_persisted_release(): void
    {
        $data = app(WorkOrderPrintService::class)->build($this->workOrder, $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['qualite']['status']);
        $this->assertSame('none', $data->visaSnapshot['qualite']['evidence_type']);
    }

    public function test_quality_visa_is_acquired_when_an_eligible_release_source_exists(): void
    {
        $this->app->instance(WorkOrderQualityReleaseSource::class, new class($this->admin) implements WorkOrderQualityReleaseSource
        {
            public function __construct(private User $user) {}

            public function latestFor(WorkOrder $workOrder): ?WorkOrderQualityReleaseData
            {
                return new WorkOrderQualityReleaseData(
                    id: 45,
                    eligible: true,
                    requirementsPresent: true,
                    releasedBy: $this->user,
                    releasedAt: '2026-11-10T09:00:00+00:00',
                    reference: 'OF-TEST / release#45',
                    snapshotHash: str_repeat('a', 64),
                );
            }
        });

        $data = app(WorkOrderPrintService::class)->build($this->workOrder, $this->admin);

        $this->assertSame('effectue', $data->visaSnapshot['qualite']['status']);
        $this->assertSame($this->admin->name, $data->visaSnapshot['qualite']['person_name']);
        $this->assertSame('Administrateur', $data->visaSnapshot['qualite']['function_label']);
        $this->assertSame(45, $data->visaSnapshot['qualite']['evidence_id']);
    }

    public function test_completed_operation_is_progress_not_atelier_visa(): void
    {
        $operation = WorkOrderOperation::factory()
            ->forWorkOrder($this->workOrder)
            ->completed()
            ->create(['code' => 'OP-001', 'name' => 'Soudage']);

        app(WorkOrderOperationAuditService::class)->record(
            $operation,
            $this->workOrder,
            WorkshopOperationAuditEvent::Completed,
            $this->admin,
            null,
            'completed',
            'Opération terminée',
        );

        $data = app(WorkOrderPrintService::class)->build($this->workOrder->fresh(), $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['atelier']['status']);
        $this->assertNotNull($data->lastCompletedOperation);
        $this->assertSame('OP-001', $data->lastCompletedOperation['code']);
        $this->assertSame('Soudage', $data->lastCompletedOperation['name']);
    }

    public function test_archived_document_content_is_immutable(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);

        $this->expectException(RuntimeException::class);

        $document->sha256 = str_repeat('b', 64);
        $document->save();
    }

    public function test_generation_service_allows_an_authorized_admin(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);

        $this->assertSame($this->workOrder->getKey(), $document->work_order_id);
        $this->assertSame(GeneratedDocumentStatus::Archived, $document->status);
    }

    public function test_generation_service_refuses_workshop_supervisor(): void
    {
        $workshop = User::factory()->workshopSupervisor()->create();

        $this->expectException(AuthorizationException::class);

        app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $workshop);
    }

    public function test_generation_service_refuses_unauthorized_user(): void
    {
        $operator = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $operator);
    }

    public function test_generation_service_refuses_a_missing_actor(): void
    {
        $this->expectException(ValidationException::class);

        app(WorkOrderPdfGenerationService::class)->generate($this->workOrder);
    }

    public function test_be_visa_is_acquired_when_approval_is_persisted(): void
    {
        $data = app(WorkOrderPrintService::class)->build($this->workOrder, $this->admin);

        $this->assertSame('effectue', $data->visaSnapshot['bureau_etudes']['status']);
        $this->assertSame($this->admin->name, $data->visaSnapshot['bureau_etudes']['person_name']);
        $this->assertNotNull($data->visaSnapshot['bureau_etudes']['validated_at']);
    }

    public function test_be_visa_is_not_acquired_after_a_technical_modification(): void
    {
        app(WorkOrderService::class)->update(
            $this->workOrder,
            ['description' => 'Modification technique après approbation.'],
            $this->admin,
        );

        $workOrder = $this->workOrder->fresh();

        $this->assertNull($workOrder->be_approved_by);
        $this->assertNull($workOrder->be_approved_at);

        $data = app(WorkOrderPrintService::class)->build($workOrder, $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['bureau_etudes']['status']);
        $this->assertSame('none', $data->visaSnapshot['bureau_etudes']['evidence_type']);
        $this->assertNull($data->visaSnapshot['bureau_etudes']['person_name']);
    }

    public function test_historical_be_audit_does_not_restore_an_invalidated_visa(): void
    {
        app(WorkOrderAuditService::class)->record(
            $this->workOrder,
            WorkOrderAuditEvent::BeApproved,
            $this->admin,
            null,
            WorkOrderStatus::Released->value,
            'Approbation BE historique.',
        );

        app(WorkOrderService::class)->update(
            $this->workOrder,
            ['description' => 'Modification technique après approbation.'],
            $this->admin,
        );

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $this->workOrder->getKey(),
            'event' => WorkOrderAuditEvent::BeApproved->value,
        ]);

        $data = app(WorkOrderPrintService::class)->build($this->workOrder->fresh(), $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['bureau_etudes']['status']);
    }

    public function test_release_visa_is_acquired_when_release_is_persisted(): void
    {
        $data = app(WorkOrderPrintService::class)->build($this->workOrder, $this->admin);

        $this->assertSame('effectue', $data->visaSnapshot['liberation']['status']);
        $this->assertSame($this->admin->name, $data->visaSnapshot['liberation']['person_name']);
        $this->assertNotNull($data->visaSnapshot['liberation']['validated_at']);
    }

    public function test_historical_release_audit_does_not_restore_an_invalidated_visa(): void
    {
        app(WorkOrderAuditService::class)->record(
            $this->workOrder,
            WorkOrderAuditEvent::Released,
            $this->admin,
            WorkOrderStatus::Ready->value,
            WorkOrderStatus::Released->value,
            'Libération historique.',
        );

        $this->workOrder->forceFill([
            'released_by' => null,
            'released_at' => null,
        ])->save();

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $this->workOrder->getKey(),
            'event' => WorkOrderAuditEvent::Released->value,
        ]);

        $data = app(WorkOrderPrintService::class)->build($this->workOrder->fresh(), $this->admin);

        $this->assertSame('non_effectue', $data->visaSnapshot['liberation']['status']);
        $this->assertSame('none', $data->visaSnapshot['liberation']['evidence_type']);
    }

    public function test_only_release_or_re_review_snapshots_freeze_documents(): void
    {
        $document = Document::factory()->create([
            'job_id' => $this->job->getKey(),
            'work_order_id' => $this->workOrder->getKey(),
            'status' => DocumentStatus::Active,
            'reference' => 'DOC-BE-001',
            'title' => 'Plan de charpente',
        ]);

        $beVersion = DocumentVersion::factory()->approved()->create([
            'document_id' => $document->getKey(),
            'revision' => 'B',
            'version_number' => 2,
        ]);

        $releaseVersion = DocumentVersion::factory()->approved()->create([
            'document_id' => $document->getKey(),
            'revision' => 'C',
            'version_number' => 3,
        ]);

        WorkOrderDocumentSnapshot::factory()->create([
            'work_order_id' => $this->workOrder->getKey(),
            'document_id' => $document->getKey(),
            'document_version_id' => $beVersion->getKey(),
            'category' => $document->category,
            'title' => $document->title,
            'reference' => $document->reference,
            'revision' => 'B',
            'version_number' => 2,
            'sha256' => $beVersion->sha256,
            'captured_at' => now()->subHour(),
            'reason' => DocumentSnapshotReason::BeApproval,
        ]);

        WorkOrderDocumentSnapshot::factory()->create([
            'work_order_id' => $this->workOrder->getKey(),
            'document_id' => $document->getKey(),
            'document_version_id' => $releaseVersion->getKey(),
            'category' => $document->category,
            'title' => $document->title,
            'reference' => $document->reference,
            'revision' => 'C',
            'version_number' => 3,
            'sha256' => $releaseVersion->sha256,
            'captured_at' => now(),
            'reason' => DocumentSnapshotReason::Release,
        ]);

        $data = app(WorkOrderPrintService::class)->build($this->workOrder->fresh(), $this->admin);

        $this->assertCount(1, $data->documents);
        $this->assertSame('C', $data->documents[0]['revision']);
        $this->assertTrue($data->documents[0]['is_frozen']);
        $this->assertSame('C', $data->ofRevision);
        $this->assertSame('C', $data->revisionSnapshot[0]['revision']);
        $this->assertSame('frozen', $data->revisionSnapshot[0]['source']);
    }

    public function test_cross_work_order_access_follows_the_existing_role_rule(): void
    {
        $document = app(WorkOrderPdfGenerationService::class)->generate($this->workOrder, $this->admin);

        $otherWorkOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $otherDocument = app(WorkOrderPdfGenerationService::class)->generate($otherWorkOrder, $this->admin);

        $workshop = User::factory()->workshopSupervisor()->create();
        $operator = User::factory()->create();

        // Règle actuelle : l'accès est fondé sur le rôle et l'état de l'OF,
        // pas sur une affectation atelier par OF (cette notion n'existe pas
        // encore dans le modèle). Décision métier à confirmer.
        $this->assertTrue(Gate::forUser($workshop)->allows('view', $document));
        $this->assertTrue(Gate::forUser($workshop)->allows('view', $otherDocument));
        $this->assertFalse(Gate::forUser($operator)->allows('view', $otherDocument));
    }
}
