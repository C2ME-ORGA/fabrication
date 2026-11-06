<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAuditLog;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class WorkOrderResponsibleAndBEApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_with_responsible_records_it(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $responsible = User::factory()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF avec responsable',
            'responsible_id' => $responsible->id,
        ], $user);

        $this->assertSame($responsible->id, $workOrder->responsible_id);
        $this->assertInstanceOf(User::class, $workOrder->responsible);
        $this->assertSame($responsible->name, $workOrder->responsible->name);
    }

    public function test_creation_without_responsible_is_null(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF sans responsable',
        ], $user);

        $this->assertNull($workOrder->responsible_id);
    }

    public function test_responsible_can_be_updated(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $responsible = User::factory()->create();
        $otherResponsible = User::factory()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF responsable modifiable',
            'responsible_id' => $responsible->id,
        ], $user);

        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'responsible_id' => $otherResponsible->id,
        ], $user);

        $this->assertSame($otherResponsible->id, $workOrder->responsible_id);
    }

    public function test_responsible_must_be_an_existing_user(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $this->expectException(ValidationException::class);

        app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF responsable invalide',
            'responsible_id' => 999999,
        ], $user);
    }

    public function test_responsible_relation_works(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $responsible = User::factory()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF relation responsable',
            'responsible_id' => $responsible->id,
        ], $user);

        $this->assertInstanceOf(User::class, $workOrder->responsible);
        $this->assertSame($responsible->id, $workOrder->responsible->id);
    }

    public function test_responsible_cannot_be_changed_on_frozen_work_order(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $responsible = User::factory()->create();

        $workOrder = WorkOrder::factory()->completed()->create(['job_id' => $job->id]);

        $this->expectException(ValidationException::class);

        app(WorkOrderService::class)->update($workOrder, [
            'responsible_id' => $responsible->id,
        ], $user);
    }

    public function test_be_approval_fields_cannot_be_mass_assigned(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $workOrder->fill([
            'be_approved_by' => $user->id,
            'be_approved_at' => now(),
            'be_approval_batch_id' => 'forged-batch-id',
            'be_approval_snapshot_id' => 123,
        ]);

        $this->assertNull($workOrder->be_approved_by);
        $this->assertNull($workOrder->be_approved_at);
        $this->assertNull($workOrder->be_approval_batch_id);
        $this->assertNull($workOrder->be_approval_snapshot_id);
    }

    public function test_be_approval_by_authorized_user(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF à approuver par BE',
        ], $user);

        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertSame($user->id, $workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
        $this->assertInstanceOf(User::class, $workOrder->beApprover);
        $this->assertSame($user->id, $workOrder->beApprover->id);
    }

    public function test_be_approval_by_non_admin_is_rejected(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->create(); // pas admin

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF à approuver',
        ], $user);

        $this->expectException(ValidationException::class);

        app(WorkOrderService::class)->approveByBE($workOrder, $user);
    }

    public function test_be_approval_is_traced_in_audit_log(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF audit BE',
        ], $user);

        app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->id,
            'user_id' => $user->id,
            'event' => WorkOrderAuditEvent::BeApproved->value,
        ]);
    }

    public function test_release_without_be_approval_is_rejected(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF sans approbation BE',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);

        $this->expectException(ValidationException::class);

        app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);
    }

    public function test_release_with_valid_be_approval_is_allowed(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF avec approbation BE',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
        $this->assertNotNull($workOrder->released_at);
    }

    public function test_release_still_requires_active_job_with_be_approval(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF BE avec job inactif',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        app(WorkOrderService::class)->approveByBE($workOrder, $user);

        // Rendre l'affaire inactif après approbation BE.
        $job->update(['status' => JobStatus::Closed]);

        $this->expectException(ValidationException::class);

        app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);
    }

    public function test_release_rollback_on_audit_failure(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF rollback BE',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $entryCountBefore = WorkOrder::query()->where('id', $workOrder->id)->count();
        $statusBefore = $workOrder->status;

        $dispatcher = app('events');
        $listener = function (): void {
            throw new RuntimeException('Échec simulé de l\'écriture du journal d\'audit.');
        };
        $dispatcher->listen('eloquent.creating: '.WorkOrderAuditLog::class, $listener);

        try {
            $this->expectException(RuntimeException::class);
            app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);
            $this->fail('Une exception aurait dû être levée.');
        } finally {
            $dispatcher->forget('eloquent.creating: '.WorkOrderAuditLog::class);
        }

        // Rollback : le statut n'a pas changé (toujours Ready).
        $this->assertSame($statusBefore, $workOrder->fresh()->status);
        // Rollback : toujours pas Released.
        $this->assertSame(WorkOrderStatus::Ready, $workOrder->fresh()->status);
    }

    public function test_be_approval_preserved_on_title_change(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'Titre original',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);

        // Modification administrative : l'approbation BE est conservée.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'title' => 'Titre modifié',
        ], $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
    }

    public function test_be_approval_preserved_on_notes_change(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF notes',
            'notes' => 'Note originale',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);

        // Modification administrative : l'approbation BE est conservée.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'notes' => 'Note modifiée',
        ], $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
    }

    public function test_be_approval_preserved_on_priority_change(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF priorité',
            'priority' => WorkOrderPriority::Normal,
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);

        // Modification administrative : l'approbation BE est conservée.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'priority' => WorkOrderPriority::Urgent,
        ], $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
    }

    public function test_be_approval_preserved_on_responsible_change(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $responsible = User::factory()->create();
        $otherResponsible = User::factory()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF responsable',
            'responsible_id' => $responsible->id,
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);

        // Modification administrative : l'approbation BE est conservée.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'responsible_id' => $otherResponsible->id,
        ], $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
        $this->assertSame($otherResponsible->id, $workOrder->responsible_id);
    }

    public function test_be_approval_invalidated_on_technical_change(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF technique',
            'description' => 'Description originale',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);

        // Modification technique : l'approbation BE est invalidée.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'description' => 'Description modifiée',
        ], $user);

        $this->assertNull($workOrder->be_approved_by);
        $this->assertNull($workOrder->be_approved_at);
    }

    public function test_be_approval_invalidated_is_traced_in_audit_log(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF invalide audit',
            'description' => 'Description originale',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'description' => 'Description modifiée',
        ], $user);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->id,
            'user_id' => $user->id,
            'event' => WorkOrderAuditEvent::BeApprovalInvalidated->value,
        ]);
    }

    public function test_new_be_approval_required_after_invalidation(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF réapprobation',
            'description' => 'Description originale',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        // Invalidation via modification technique.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'description' => 'Description modifiée',
        ], $user);

        $this->assertNull($workOrder->be_approved_by);

        // Une nouvelle approbation est requise avant libération.
        $this->expectException(ValidationException::class);
        app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        // Nouvelle approbation puis libération autorisée.
        app(WorkOrderService::class)->approveByBE($workOrder, $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
    }

    public function test_release_refused_after_invalidation(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF refus après invalide',
            'description' => 'Description originale',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        // Invalidation via modification technique.
        $workOrder = app(WorkOrderService::class)->update($workOrder, [
            'description' => 'Description modifiée',
        ], $user);

        $this->assertNull($workOrder->be_approved_by);

        // La libération doit être refusée.
        $this->expectException(ValidationException::class);
        app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Released, $user);
    }

    public function test_be_approver_relation_works(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = app(WorkOrderService::class)->create([
            'job_id' => $job->id,
            'title' => 'OF relation BE approver',
            'description' => 'Description',
        ], $user);
        $workOrder = app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = app(WorkOrderService::class)->approveByBE($workOrder, $user);

        $this->assertInstanceOf(User::class, $workOrder->beApprover);
        $this->assertSame($user->id, $workOrder->beApprover->id);
    }

    /**
     * Les champs d'approbation BE sont protégés : ils ne peuvent jamais être
     * fournis par un formulaire ou un payload, ni à la création ni à la
     * modification. Seul le service les écrit via approveByBE().
     */
    public function test_be_approval_fields_cannot_be_mass_assigned(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        // Création : chaque champ BE fourni doit être rejeté.
        foreach ([
            'be_approved_by' => $user->id,
            'be_approved_at' => now(),
            'be_approval_batch_id' => 'forged-batch',
            'be_approval_snapshot_id' => 1,
        ] as $field => $value) {
            try {
                $this->service->create([
                    'job_id' => $job->id,
                    'title' => "OF masse assignée {$field}",
                    $field => $value,
                ], $user);

                $this->fail("La création aurait dû rejeter le champ protégé {$field}.");
            } catch (ValidationException) {
                // attendu
            }
        }

        $this->assertDatabaseMissing('work_orders', ['title' => 'OF masse assignée be_approved_by']);

        // Modification : chaque champ BE fourni doit également être rejeté.
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF champs protégés',
        ], $user);

        foreach ([
            'be_approved_by' => $user->id,
            'be_approved_at' => now(),
            'be_approval_batch_id' => 'forged-batch',
            'be_approval_snapshot_id' => 1,
        ] as $field => $value) {
            try {
                $this->service->update($workOrder, [$field => $value], $user);

                $this->fail("L'update aurait dû rejeter le champ protégé {$field}.");
            } catch (ValidationException) {
                // attendu
            }
        }

        $fresh = $workOrder->fresh();
        $this->assertNull($fresh->be_approved_by);
        $this->assertNull($fresh->be_approved_at);
        $this->assertNull($fresh->be_approval_batch_id);
        $this->assertNull($fresh->be_approval_snapshot_id);
    }
}
