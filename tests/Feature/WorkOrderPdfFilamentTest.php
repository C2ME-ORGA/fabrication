<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Workshop\Resources\WorkshopOperations\Pages\ViewWorkshopOperation;
use App\Models\GeneratedDocument;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\WorkOrderPdfGenerationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderPdfFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_pdf_from_view_page_and_see_viewer(): void
    {
        Storage::fake('generated_documents');

        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        $workOrder = WorkOrder::factory()->create([
            'job_id' => $job->getKey(),
            'status' => WorkOrderStatus::Released,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->assertActionExists('generatePdf')
            ->callAction('generatePdf')
            ->assertHasNoActionErrors();

        $document = GeneratedDocument::query()->firstOrFail();

        $this->assertSame($workOrder->getKey(), $document->work_order_id);
        $this->assertTrue(Storage::disk('generated_documents')->exists($document->path));

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->getKey()}")
            ->assertOk()
            ->assertSee(route('generated-documents.view', $document), false);
    }

    public function test_workshop_operation_page_exposes_pdf_link_read_only(): void
    {
        Storage::fake('generated_documents');

        $admin = User::factory()->admin()->create();
        $workshop = User::factory()->workshopSupervisor()->create();
        $job = Job::factory()->active()->create();
        $workOrder = WorkOrder::factory()->create([
            'job_id' => $job->getKey(),
            'status' => WorkOrderStatus::Released,
        ]);
        $operation = WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create();

        app(WorkOrderPdfGenerationService::class)->generate($workOrder, $admin);

        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));

        try {
            Livewire::actingAs($workshop)
                ->test(ViewWorkshopOperation::class, ['record' => $operation->getRouteKey()])
                ->assertActionExists('workOrderPdf');
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }
}
