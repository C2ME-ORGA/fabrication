<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\AuditLogsRelationManager;
use App\Filament\Resources\Documents\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Filament\Resources\Jobs\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\Document;
use App\Models\Job;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class DocumentFilamentTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();
    }

    public function test_admins_can_access_document_resource_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();

        $this->actingAs($admin)->get('/admin/documents')->assertOk()->assertSee($document->title);
        $this->actingAs($admin)->get('/admin/documents/create')->assertOk();
        $this->actingAs($admin)->get("/admin/documents/{$document->id}")->assertOk();
        $this->actingAs($admin)->get("/admin/documents/{$document->id}/edit")->assertOk();
    }

    public function test_non_admins_cannot_access_document_resource_pages(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create();

        $this->actingAs($user)->get('/admin/documents')->assertForbidden();
        $this->actingAs($user)->get('/admin/documents/create')->assertForbidden();
        $this->actingAs($user)->get("/admin/documents/{$document->id}")->assertForbidden();
        $this->actingAs($user)->get("/admin/documents/{$document->id}/edit")->assertForbidden();
    }

    public function test_a_document_can_be_created_from_the_filament_form(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();

        $this->actingAs($admin);

        Livewire::test(CreateDocument::class)
            ->fillForm([
                'job_id' => $job->id,
                'title' => 'Document Filament',
                'reference' => 'DOC-FIL-1',
                'category' => 'assembly_plan',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = Document::query()->where('title', 'Document Filament')->firstOrFail();

        $this->assertSame($job->id, $document->job_id);
        $this->assertSame($admin->id, $document->created_by);
        $this->assertSame('active', $document->getRawOriginal('status'));
    }

    public function test_the_document_view_shows_versions_history_and_impact_section_and_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();
        $this->approvedVersion($document, 'A', $admin);

        $this->actingAs($admin);

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSee('Versions')
            ->assertSee('Historique')
            ->assertSee('Impact des nouvelles révisions')
            ->assertActionExists('uploadVersion')
            ->assertActionExists('archive');
    }

    public function test_an_admin_can_upload_a_new_version_from_the_document_view(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('uploadVersion', data: [
                'revision' => 'A',
                'file' => $this->pdfUpload('nouvelle-version.pdf'),
                'comment' => 'Premier dépôt',
            ])
            ->assertHasNoActionErrors();

        $version = $document->fresh()->currentVersion;

        $this->assertNotNull($version);
        $this->assertSame('A', $version->revision);
        $this->assertSame(1, $version->version_number);
        $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
    }

    public function test_document_relation_managers_render_versions_and_history(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();
        $version = $this->approvedVersion($document, 'A', $admin);

        $this->actingAs($admin);

        Livewire::test(VersionsRelationManager::class, [
            'ownerRecord' => $document,
            'pageClass' => ViewDocument::class,
        ])
            ->assertCanSeeTableRecords([$version])
            ->assertTableActionExists('download')
            ->assertTableActionHidden('submit', $version);

        Livewire::test(AuditLogsRelationManager::class, [
            'ownerRecord' => $document,
            'pageClass' => ViewDocument::class,
        ])->assertSee('Version approuvée');
    }

    public function test_the_document_view_lists_released_work_orders_impacted_by_a_new_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF impacté',
        ], $admin);
        $document = app(DocumentService::class)->create([
            'job_id' => $job->id,
            'category' => 'assembly_plan',
            'title' => 'Plan impacté',
        ], $admin);
        $this->approvedVersion($document, 'A', $admin);

        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $admin);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $admin);
        $this->approvedVersion($document->fresh(), 'B', $admin);

        $this->actingAs($admin)
            ->get("/admin/documents/{$document->id}")
            ->assertOk()
            ->assertSee($workOrder->number)
            ->assertSee('utilisent encore une révision antérieure');
    }

    public function test_the_work_order_view_offers_the_explicit_re_review_action(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF revue',
        ], $admin);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $admin);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $admin);

        $this->actingAs($admin);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->assertActionExists('reReviewDocuments');
    }

    public function test_the_job_page_lists_its_documents(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        $document = Document::factory()->create(['job_id' => $job->id, 'title' => 'Plan affaire visible']);

        $this->actingAs($admin)
            ->get("/admin/jobs/{$job->id}")
            ->assertOk()
            ->assertSee('Documents');

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $job,
            'pageClass' => ViewJob::class,
        ])->assertCanSeeTableRecords([$document]);
    }

    public function test_the_work_order_page_shows_applicable_and_frozen_documents(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF documents UI',
        ], $admin);

        $document = app(DocumentService::class)->create([
            'job_id' => $job->id,
            'category' => 'assembly_plan',
            'title' => 'Plan applicable UI',
        ], $admin);
        $this->approvedVersion($document, 'A', $admin);

        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $admin);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $admin);

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertSee('Plan applicable UI')
            ->assertSee('Références figées à la libération')
            ->assertSee('Documents techniques');
    }
}
