<?php

namespace Tests\Feature;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DocumentService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();
    }

    public function test_a_document_can_be_attached_to_a_job(): void
    {
        $job = Job::factory()->active()->create();
        $actor = User::factory()->admin()->create();

        $document = app(DocumentService::class)->create([
            'job_id' => $job->id,
            'category' => DocumentCategory::AssemblyPlan->value,
            'title' => "Plan d'ensemble",
            'reference' => 'PL-001',
        ], $actor);

        $this->assertSame(DocumentStatus::Active, $document->status);
        $this->assertSame($job->id, $document->job_id);
        $this->assertNull($document->work_order_id);
        $this->assertSame($actor->id, $document->created_by);
        $this->assertSame(DocumentCategory::AssemblyPlan, $document->category);
        $this->assertDatabaseHas('document_audit_logs', [
            'document_id' => $document->id,
            'action' => 'document_created',
            'user_id' => $actor->id,
        ]);
    }

    public function test_a_document_can_be_attached_only_to_a_work_order(): void
    {
        $job = Job::factory()->active()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $document = app(DocumentService::class)->create([
            'work_order_id' => $workOrder->id,
            'category' => DocumentCategory::DetailPlan->value,
            'title' => 'Plan de détail',
        ]);

        $this->assertNull($document->job_id);
        $this->assertSame($workOrder->id, $document->work_order_id);
        $this->assertSame($job->id, $document->resolvedJobId());
        $this->assertTrue($document->workOrder->is($workOrder));
    }

    public function test_a_document_can_be_attached_to_a_job_and_its_work_order(): void
    {
        $job = Job::factory()->active()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $document = app(DocumentService::class)->create([
            'job_id' => $job->id,
            'work_order_id' => $workOrder->id,
            'category' => DocumentCategory::FabricationPlan->value,
            'title' => 'Plan de fabrication',
        ]);

        $this->assertSame($job->id, $document->job_id);
        $this->assertSame($workOrder->id, $document->work_order_id);
    }

    public function test_an_incoherent_job_and_work_order_association_is_rejected(): void
    {
        $job = Job::factory()->active()->create();
        $otherJob = Job::factory()->active()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $otherJob->id]);

        $this->expectException(ValidationException::class);

        app(DocumentService::class)->create([
            'job_id' => $job->id,
            'work_order_id' => $workOrder->id,
            'category' => DocumentCategory::Other->value,
            'title' => 'Incohérent',
        ]);
    }

    public function test_a_document_must_have_at_least_one_attachment(): void
    {
        $this->expectException(ValidationException::class);

        app(DocumentService::class)->create([
            'category' => DocumentCategory::Other->value,
            'title' => 'Sans rattachement',
        ]);
    }

    public function test_the_association_cannot_be_changed_after_a_version_exists(): void
    {
        $job = Job::factory()->active()->create();
        $otherJob = Job::factory()->active()->create();
        $document = Document::factory()->create(['job_id' => $job->id]);

        $this->uploadVersion($document, 'A');

        $this->expectException(ValidationException::class);

        app(DocumentService::class)->update($document, [
            'job_id' => $otherJob->id,
            'category' => $document->category->value,
            'title' => $document->title,
        ]);
    }

    public function test_a_document_can_be_archived_and_cannot_receive_new_versions(): void
    {
        $actor = User::factory()->admin()->create();
        $document = Document::factory()->create();

        app(DocumentService::class)->archive($document, $actor, 'Fin de chantier');

        $this->assertSame(DocumentStatus::Archived, $document->fresh()->status);
        $this->assertFalse(Gate::forUser($actor)->allows('uploadVersion', $document->fresh()));
        $this->assertDatabaseHas('document_audit_logs', [
            'document_id' => $document->id,
            'action' => 'document_archived',
            'user_id' => $actor->id,
            'comment' => 'Fin de chantier',
        ]);

        try {
            app(DocumentService::class)->update($document->fresh(), [
                'job_id' => $document->job_id,
                'category' => $document->category->value,
                'title' => 'Modification interdite',
            ]);
            $this->fail('Un document archivé ne doit pas être modifiable.');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        $this->uploadVersion($document->fresh(), 'A', $actor);
    }

    public function test_documents_are_never_deleted_through_the_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $document));
    }

    public function test_a_job_holding_documents_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        Document::factory()->create(['job_id' => $job->id]);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $job));
    }

    public function test_a_work_order_holding_documents_cannot_be_deleted(): void
    {
        $job = Job::factory()->active()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);
        Document::factory()->create([
            'job_id' => null,
            'work_order_id' => $workOrder->id,
        ]);

        $this->expectException(ValidationException::class);

        app(WorkOrderService::class)->delete($workOrder);
    }
}
