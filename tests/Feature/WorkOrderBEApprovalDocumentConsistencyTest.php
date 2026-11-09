<?php

namespace Tests\Feature;

use App\Enums\DocumentCategory;
use App\Enums\DocumentSnapshotReason;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderStatus;
use App\Models\Document;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocumentSnapshot;
use App\Services\DocumentService;
use App\Services\DocumentVersionService;
use App\Services\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class WorkOrderBEApprovalDocumentConsistencyTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected WorkOrderService $workOrders;

    protected DocumentService $documents;

    protected DocumentVersionService $versions;

    protected User $admin;

    protected Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();

        $this->workOrders = app(WorkOrderService::class);
        $this->documents = app(DocumentService::class);
        $this->versions = app(DocumentVersionService::class);
        $this->admin = User::factory()->admin()->create();
        $this->job = Job::factory()->active()->create();
    }

    // === Capture initiale ===

    public function test_be_approval_captures_approved_versions_of_applicable_documents(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF avec documents',
        ], $this->admin);

        $docA = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan A',
        ], $this->admin);
        $versionA = $this->approvedVersion($docA, 'A', $this->admin);

        $docB = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::DetailPlan,
            'title' => 'Plan B',
        ], $this->admin);
        $versionB = $this->approvedVersion($docB, 'B', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
        $this->assertNotNull($workOrder->be_approval_snapshot_id);

        $snapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::BeApproval->value)
            ->get();

        $this->assertCount(2, $snapshots);

        foreach ($snapshots as $snapshot) {
            $this->assertNotNull($snapshot->document_version_id);
            $this->assertNotNull($snapshot->revision);
            $this->assertNotNull($snapshot->sha256);
            $this->assertNotNull($snapshot->captured_at);
        }

        $this->assertTrue(
            $snapshots->contains(fn ($s) => $s->document_id === $docA->id
                && $s->document_version_id === $versionA->id
                && $s->revision === 'A'),
            'Snapshot du document A avec version A devrait exister.'
        );
        $this->assertTrue(
            $snapshots->contains(fn ($s) => $s->document_id === $docB->id
                && $s->document_version_id === $versionB->id
                && $s->revision === 'B'),
            'Snapshot du document B avec version B devrait exister.'
        );

        // Traçabilité dans le journal d'audit.
        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->id,
            'event' => WorkOrderAuditEvent::BeApproved->value,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_be_approval_captures_document_without_approved_version(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF doc sans version',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Doc sans version approuvée',
        ], $this->admin);

        // Pas de version approuvée créée.

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        $snapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::BeApproval->value)
            ->get();

        $this->assertCount(1, $snapshots);

        $snapshot = $snapshots->first();
        $this->assertNull($snapshot->document_version_id);
        $this->assertNull($snapshot->revision);
        $this->assertNull($snapshot->sha256);
        $this->assertNotNull($snapshot->captured_at);
        $this->assertSame($doc->id, $snapshot->document_id);
    }

    // === Comparaison à la libération ===

    public function test_release_allowed_when_document_state_unchanged(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF état inchangé',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Aucun changement documentaire : libération autorisée.
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
        $this->assertNotNull($workOrder->released_at);

        // Les snapshots de libération existent.
        $releaseSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->get();
        $this->assertCount(1, $releaseSnapshots);
    }

    public function test_release_refused_when_new_version_approved_after_be_approval(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF nouvelle version',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
        ], $this->admin);
        $versionA = $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Nouvelle version approuvée après l'approbation BE.
        $versionB = $this->approvedVersion($doc, 'B', $this->admin);

        $this->assertNotSame($versionA->id, $versionB->id);

        // La libération doit être refusée car l'approbation BE est obsolète.
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // Aucun snapshot de libération créé.
        $releaseSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->get();
        $this->assertCount(0, $releaseSnapshots);

        // L'obsolescence est tracée dans le journal d'audit.
        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->id,
            'event' => WorkOrderAuditEvent::BeApprovalObsolete->value,
        ]);

        // L'approbation BE est invalidée.
        $this->assertNull($workOrder->fresh()->be_approved_by);
        $this->assertNull($workOrder->fresh()->be_approved_at);
    }

    public function test_release_refused_when_new_document_becomes_applicable(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF nouveau document',
        ], $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Aucun document applicable au moment de l'approbation BE.
        $this->assertCount(0, WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::BeApproval->value)
            ->get());

        // Un nouveau document devient applicable après l'approbation BE.
        $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Nouveau plan',
        ], $this->admin);

        // La libération doit être refusée.
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);
    }

    public function test_release_refused_when_document_without_version_gets_approved(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF doc sans version puis approuvé',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Doc d\'abord sans version',
        ], $this->admin);

        // Pas de version approuvée au moment de l'approbation BE.
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Une version est approuvée après.
        $this->approvedVersion($doc, 'A', $this->admin);

        // La libération doit être refusée car l'état documentaire a changé.
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);
    }

    public function test_release_allowed_when_new_version_created_but_not_approved(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF version non approuvée',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
        ], $this->admin);
        $versionA = $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Une nouvelle version est créée mais pas encore approuvée.
        $this->versions->upload(
            $doc,
            $this->pdfUpload('plan-b.pdf'),
            'B',
            $this->admin,
        );

        // La libération doit être autorisée car la version approuvée n'a pas changé.
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
    }

    public function test_new_be_approval_required_after_obsolescence(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF réapprobation',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
        ], $this->admin);
        $versionA = $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Nouvelle version approuvée : obsolescence.
        $versionB = $this->approvedVersion($doc, 'B', $this->admin);

        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // Nouvelle approbation requise.
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);
        $this->assertNotNull($workOrder->be_approved_by);

        // La libération est maintenant autorisée avec la nouvelle version.
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);
        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);

        // L'ancien snapshot BE est conservé.
        $oldSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::BeApproval->value)
            ->get();
        $this->assertCount(2, $oldSnapshots); // 1ère approbation + 2ème approbation
    }

    public function test_be_approval_preserved_on_released_work_order(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF libéré',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // L'OF est libéré : l'approbation BE et les snapshots sont conservés.
        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);

        // L'ancien snapshot BE est encore là.
        $beSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::BeApproval->value)
            ->get();
        $this->assertCount(1, $beSnapshots);

        // Le snapshot de libération est aussi là.
        $releaseSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->get();
        $this->assertCount(1, $releaseSnapshots);
    }

    public function test_release_refused_when_document_title_changed_after_be_approval(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF titre modifié',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan original',
            'reference' => 'REF-001',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Modifier le titre du document après approbation BE.
        $this->documents->update($doc, [
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan modifié',
            'reference' => 'REF-001',
        ], $this->admin);

        // La libération doit être refusée.
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // Aucun snapshot de libération créé.
        $releaseSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->get();
        $this->assertCount(0, $releaseSnapshots);
    }

    public function test_release_refused_when_document_reference_changed_after_be_approval(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF ref modifiée',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
            'reference' => 'REF-001',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Modifier la référence du document après approbation BE.
        $this->documents->update($doc, [
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
            'reference' => 'REF-002',
        ], $this->admin);

        // La libération doit être refusée.
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        $releaseSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->get();
        $this->assertCount(0, $releaseSnapshots);
    }

    public function test_release_refused_when_document_category_changed_after_be_approval(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF catégorie modifiée',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
            'reference' => 'REF-001',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Modifier la catégorie du document après approbation BE.
        $this->documents->update($doc, [
            'category' => DocumentCategory::DetailPlan,
            'title' => 'Plan',
            'reference' => 'REF-001',
        ], $this->admin);

        // La libération doit être refusée.
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        $releaseSnapshots = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->get();
        $this->assertCount(0, $releaseSnapshots);
    }

    public function test_release_allowed_after_metadata_change_and_new_be_approval(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF réapprobation métadonnées',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan original',
            'reference' => 'REF-001',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Modifier les métadonnées après approbation BE.
        $this->documents->update($doc, [
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan modifié',
            'reference' => 'REF-001',
        ], $this->admin);

        // La libération doit être refusée (approbation obsolète).
        $this->expectException(ValidationException::class);
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // Nouvelle approbation BE après modification des métadonnées.
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);
        $this->assertNotNull($workOrder->be_approved_by);

        // La libération est maintenant autorisée.
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);
        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
    }

    public function test_be_approval_by_non_admin_is_refused(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF non admin',
        ], $this->admin);

        $nonAdmin = User::factory()->create(); // pas admin

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);

        $this->expectException(ValidationException::class);
        $this->workOrders->approveByBE($workOrder, $nonAdmin);
    }

    public function test_be_approval_invalidated_on_released_work_order_not_possible(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF déjà libéré',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // Une nouvelle version après libération ne change rien à l'approbation historique.
        $this->approvedVersion($doc, 'B', $this->admin);

        $workOrder->refresh();
        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
    }

    public function test_release_refused_when_metadata_changes_without_approved_version(): void
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF métadonnées sans version',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan initial',
            'reference' => 'REF-001',
        ], $this->admin);

        // Aucun document_version approuvé au moment de l'approbation BE.
        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        $this->documents->update($doc, [
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan renommé',
            'reference' => 'REF-001',
        ], $this->admin);

        // Le changement de métadonnées doit rendre l'approbation BE obsolète,
        // même si aucune version approuvée n'existait au moment du contrôle.
        try {
            $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);
            $this->fail('La libération aurait dû être refusée après modification des métadonnées.');
        } catch (ValidationException) {
            // L'audit d'obsolescence est écrit hors de la transaction annulée :
            // il doit survivre au rollback de la libération refusée.
            $this->assertDatabaseHas('work_order_audit_logs', [
                'work_order_id' => $workOrder->id,
                'event' => WorkOrderAuditEvent::BeApprovalObsolete->value,
            ]);
        }

        // La libération refusée ne change pas le statut de l'OF.
        $this->assertSame(WorkOrderStatus::Ready, $workOrder->fresh()->status);

        // Et ne laisse aucun snapshot de libération valide.
        $this->assertSame(0, WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->count());
    }

    /**
     * Vérifie, sur MySQL/MariaDB avec une seconde connexion réelle
     * (`mysql_probe`, même pattern que WorkOrderNumberGeneratorTest), que la
     * libération d'un OF verrouille le Job parent. DocumentService::create()
     * acquiert ce même verrou en premier : une création concurrente de
     * document applicable est donc bloquée jusqu'à la fin de la libération.
     */
    public function test_concurrent_creation_during_release_is_serialized(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Test de concurrence réel nécessite MySQL/MariaDB. '
                .'Lancez avec phpunit.mysql.xml et une base de test dédiée.'
            );
        }

        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF concurrence création',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan applicable',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // La libération acquiert les verrous dans la transaction de test
        // (RefreshDatabase) : ils restent tenus jusqu'au rollback final,
        // exactement comme dans WorkOrderNumberGeneratorTest.
        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // Connexion indépendante : elle doit échouer à verrouiller le Job
        // parent, ce qui prouve qu'une création concurrente serait bloquée.
        $this->assertProbeCannotLock('jobs', $this->job->id);

        // Le document applicable est également verrouillé (métadonnées).
        $this->assertProbeCannotLock('documents', $doc->id);
    }

    /**
     * Vérifie que la libération verrouille le Document parent. C'est le
     * premier verrou acquis par DocumentVersionService::approve() : une
     * approbation de version concurrente est donc sérialisée.
     */
    public function test_concurrent_version_approval_during_release_is_serialized(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Test de concurrence réel nécessite MySQL/MariaDB. '
                .'Lancez avec phpunit.mysql.xml et une base de test dédiée.'
            );
        }

        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF concurrence version',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan applicable',
        ], $this->admin);
        $versionA = $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        // Préparer une version B en attente de validation après l'approbation BE.
        // Son approbation concurrente doit prendre le verrou du même document.
        $versionB = $this->versions->upload(
            $doc,
            $this->pdfUpload('plan-b-pending.pdf'),
            'B',
            $this->admin,
        );
        $versionB = $this->versions->submit($versionB, $this->admin);

        $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Released, $this->admin);

        // DocumentVersionService::approve() commence par lockDocument().
        // La connexion indépendante ne peut donc pas prendre ce verrou NOWAIT.
        $this->assertProbeCannotLock('documents', $doc->id);

        // La version B n'a pas été approuvée pendant la libération.
        $this->assertSame('pending_validation', $versionB->fresh()->status->value);

        // Le snapshot de libération doit contenir la version examinée par le BE
        // (version A), et non la version B encore en attente.
        $releaseVersionId = WorkOrderDocumentSnapshot::query()
            ->where('work_order_id', $workOrder->id)
            ->where('reason', DocumentSnapshotReason::Release->value)
            ->where('document_id', $doc->id)
            ->value('document_version_id');

        $this->assertSame($versionA->id, $releaseVersionId);
    }

    /**
     * LOT 3-C : l'approbation BE verrouille le Job parent et les documents
     * applicables. Une modification de métadonnées ou l'approbation d'une
     * version concurrente (qui acquièrent les mêmes verrous) est donc
     * sérialisée avec l'approbation BE.
     */
    public function test_be_approval_locks_documents_and_job_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Test de concurrence réel nécessite MySQL/MariaDB. '
                .'Lancez avec phpunit.mysql.xml et une base de test dédiée.'
            );
        }

        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF verrou BE',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan applicable',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $this->workOrders->approveByBE($workOrder, $this->admin);

        // Les verrous acquis par approveByBE() restent tenus dans la
        // transaction de test jusqu'au rollback final.
        $this->assertProbeCannotLock('jobs', $this->job->id);
        $this->assertProbeCannotLock('documents', $doc->id);
    }

    /**
     * Ouvre une seconde connexion PDO indépendante (`mysql_probe`) et tente un
     * `SELECT ... FOR UPDATE NOWAIT` sur la ligne cible. Échoue le test si le
     * verrou peut être acquis (aucune concurrence réelle).
     */
    private function assertProbeCannotLock(string $table, int $id): void
    {
        $connection = config('database.connections.mysql');
        config(['database.connections.mysql_probe' => $connection]);
        DB::purge('mysql_probe');

        try {
            DB::connection('mysql_probe')->statement(
                "select * from {$table} where id = ? for update nowait",
                [$id],
            );

            $this->fail(
                "La ligne {$table}#{$id} aurait dû être verrouillée par la libération en cours."
            );
        } catch (QueryException $exception) {
            // MySQL 8 returns 3572 for NOWAIT; MariaDB commonly reports
            // 1205 (lock wait timeout). Do not accept unrelated SQL errors
            // as evidence that the row is locked.
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $this->assertContains(
                $driverCode,
                [1205, 3572],
                "Erreur SQL inattendue pendant la vérification du verrou : {$exception->getMessage()}"
            );
        } finally {
            DB::purge('mysql_probe');
        }
    }

    private function createBeApprovedWorkOrderWithDocument(): WorkOrder
    {
        $workOrder = $this->workOrders->create([
            'job_id' => $this->job->id,
            'title' => 'OF helper',
        ], $this->admin);

        $doc = $this->documents->create([
            'job_id' => $this->job->id,
            'category' => DocumentCategory::AssemblyPlan,
            'title' => 'Plan helper',
        ], $this->admin);
        $this->approvedVersion($doc, 'A', $this->admin);

        $workOrder = $this->workOrders->transitionTo($workOrder, WorkOrderStatus::Ready, $this->admin);
        $workOrder = $this->workOrders->approveByBE($workOrder, $this->admin);

        return $workOrder;
    }
}
