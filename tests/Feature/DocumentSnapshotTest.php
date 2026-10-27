<?php

namespace Tests\Feature;

use App\Enums\DocumentSnapshotReason;
use App\Enums\DocumentVersionStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Document;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DocumentApplicabilityService;
use App\Services\DocumentService;
use App\Services\DocumentSnapshotService;
use App\Services\DocumentVersionService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class DocumentSnapshotTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected WorkOrderService $workOrders;

    protected DocumentService $documents;

    protected DocumentVersionService $versions;

    protected DocumentSnapshotService $snapshots;

    protected User $admin;

    protected Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();

        $this->workOrders = app(WorkOrderService::class);
        $this->documents = app(DocumentService::class);
        $this->versions = app(DocumentVersionService::class);
        $this->snapshots = app(DocumentSnapshotService::class);
        $this->admin = User::factory()->admin()->create();
        $this->job = Job::factory()->active()->create();
    }

    public function test_release_freezes_the_latest_approved_versions_applicable_to_the_work_order(): void
    {
        $workOrder = $this->createWorkOrder();
        $jobDocument = $this->documentForJob('Plan d’ensemble');
        $workOrderDocument = $this->documentForWorkOrder($workOrder, 'Plan de détail');
        $jobVersion = $this->approvedVersion($jobDocument, 'A', $this->admin);
        $workOrderVersion = $this->approvedVersion($workOrderDocument, 'A', $this->admin);

        $workOrder = $this->release($workOrder);

        $snapshots = $workOrder->documentSnapshots()->where('reason', 'release')->get();

        $this->assertCount(2, $snapshots);
        $this->assertTrue($snapshots->contains(fn ($snapshot): bool => $snapshot->document_version_id === $jobVersion->id));
        $this->assertTrue($snapshots->contains(fn ($snapshot): bool => $snapshot->document_version_id === $workOrderVersion->id));

        foreach ($snapshots as $snapshot) {
            $this->assertSame(DocumentSnapshotReason::Release, $snapshot->reason);
            $this->assertSame($this->admin->id, $snapshot->validated_by);
            $this->assertNotNull($snapshot->validated_at);
            $this->assertSame($this->admin->id, $snapshot->captured_by);
            $this->assertNotNull($snapshot->captured_at);
            $this->assertSame($workOrder->id, $snapshot->work_order_id);
        }
    }

    public function test_pending_and_rejected_versions_are_not_used_for_release(): void
    {
        $workOrder = $this->createWorkOrder();

        $pendingDocument = $this->documentForJob('En attente');
        $pendingVersion = $this->uploadVersion($pendingDocument, 'A', $this->admin);
        $this->versions->submit($pendingVersion, $this->admin);

        $rejectedDocument = $this->documentForJob('Refusé');
        $rejectedVersion = $this->uploadVersion($rejectedDocument, 'A', $this->admin);
        $this->versions->submit($rejectedVersion, $this->admin);
        $this->versions->reject($rejectedVersion, $this->admin, 'Indice incohérent');

        $approvedDocument = $this->documentForJob('Ancienne version approuvée');
        $approvedVersion = $this->approvedVersion($approvedDocument, 'A', $this->admin);
        $newDraft = $this->uploadVersion($approvedDocument, 'B', $this->admin);

        $workOrder = $this->release($workOrder);
        $snapshots = $workOrder->documentSnapshots()->where('reason', 'release')->get();

        $this->assertCount(1, $snapshots);
        $this->assertSame($approvedVersion->id, $snapshots->first()->document_version_id);
        $this->assertNotSame($pendingVersion->id, $snapshots->first()->document_version_id);
        $this->assertNotSame($rejectedVersion->id, $snapshots->first()->document_version_id);
        $this->assertNotSame($newDraft->id, $snapshots->first()->document_version_id);
    }

    public function test_a_new_approved_version_does_not_change_the_release_snapshot_and_is_signalled(): void
    {
        $workOrder = $this->createWorkOrder();
        $document = $this->documentForJob('Plan de fabrication');
        $versionA = $this->approvedVersion($document, 'A', $this->admin);

        $workOrder = $this->release($workOrder);
        $releaseSnapshot = $workOrder->documentSnapshots()->where('reason', 'release')->firstOrFail();

        $versionB = $this->approvedVersion($document, 'B', $this->admin);

        $releaseSnapshot->refresh();
        $this->assertSame($versionA->id, $releaseSnapshot->document_version_id);
        $this->assertSame(DocumentVersionStatus::Superseded, $versionA->fresh()->status);
        $this->assertSame($versionB->id, $document->fresh()->latestApprovedVersion?->id);
        $this->assertSame(
            1,
            app(DocumentApplicabilityService::class)
                ->countImpactedReleasedWorkOrders($document->fresh(), $versionB->version_number),
        );
    }

    public function test_an_explicit_re_review_appends_snapshots_without_erasing_history(): void
    {
        $workOrder = $this->createWorkOrder();
        $document = $this->documentForJob('Plan de détail');
        $versionA = $this->approvedVersion($document, 'A', $this->admin);

        $workOrder = $this->release($workOrder);
        $releaseSnapshots = $workOrder->documentSnapshots()->where('reason', 'release')->get();
        $this->assertCount(1, $releaseSnapshots);

        $versionB = $this->approvedVersion($document, 'B', $this->admin);

        $created = $this->snapshots->refreshForWorkOrder($workOrder, $this->admin, 'Revue après changement de plan');

        $this->assertCount(1, $created);
        $this->assertSame(DocumentSnapshotReason::ReReview, $created->first()->reason);
        $this->assertSame($versionB->id, $created->first()->document_version_id);
        $this->assertSame('Revue après changement de plan', $created->first()->comment);

        $this->assertCount(1, $workOrder->documentSnapshots()->where('reason', 'release')->get());
        $this->assertSame($versionA->id, $workOrder->documentSnapshots()->where('reason', 'release')->first()->document_version_id);
        $this->assertCount(2, $workOrder->documentSnapshots()->get());
        $this->assertDatabaseHas('document_audit_logs', [
            'work_order_id' => $workOrder->id,
            'action' => 'documents_re_reviewed',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_a_re_review_requires_a_comment_and_an_open_released_work_order(): void
    {
        $workOrder = $this->createWorkOrder();

        try {
            $this->snapshots->refreshForWorkOrder($workOrder, $this->admin, 'Motif');
            $this->fail('Un OF non libéré ne doit pas pouvoir être remis en revue.');
        } catch (ValidationException) {
            // expected
        }

        $workOrder = $this->release($workOrder);

        $this->expectException(ValidationException::class);
        $this->snapshots->refreshForWorkOrder($workOrder, $this->admin, '   ');
    }

    public function test_a_completed_work_order_cannot_be_re_reviewed(): void
    {
        $workOrder = $this->createWorkOrder();
        $workOrder = $this->release($workOrder);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::InProgress, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Completed, $this->admin);

        $this->expectException(ValidationException::class);

        $this->snapshots->refreshForWorkOrder($workOrder, $this->admin, 'Trop tard');
    }

    public function test_documents_attached_to_another_work_order_of_the_same_job_are_not_applicable(): void
    {
        $workOrder = $this->createWorkOrder();
        $otherWorkOrder = $this->createWorkOrder();

        $otherDocument = $this->documentForWorkOrder($otherWorkOrder, 'Plan spécifique à un autre OF');
        $this->approvedVersion($otherDocument, 'A', $this->admin);

        $workOrder = $this->release($workOrder);

        $this->assertCount(0, $workOrder->documentSnapshots);
    }

    public function test_a_rejected_version_cannot_be_used_even_if_it_is_the_only_version(): void
    {
        $workOrder = $this->createWorkOrder();
        $document = $this->documentForJob('Document refusé');
        $version = $this->uploadVersion($document, 'A', $this->admin);
        $this->versions->submit($version, $this->admin);
        $this->versions->reject($version, $this->admin, 'Non conforme');

        $workOrder = $this->release($workOrder);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
        $this->assertCount(0, $workOrder->documentSnapshots);
    }

    protected function createWorkOrder(): WorkOrder
    {
        return $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF documents',
        ], $this->admin);
    }

    protected function release(WorkOrder $workOrder): WorkOrder
    {
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);

        return $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);
    }

    protected function documentForJob(string $title): Document
    {
        return $this->documents->create([
            'job_id' => $this->job->id,
            'category' => 'assembly_plan',
            'title' => $title,
        ], $this->admin);
    }

    protected function documentForWorkOrder(WorkOrder $workOrder, string $title): Document
    {
        return $this->documents->create([
            'work_order_id' => $workOrder->id,
            'category' => 'detail_plan',
            'title' => $title,
        ], $this->admin);
    }
}
