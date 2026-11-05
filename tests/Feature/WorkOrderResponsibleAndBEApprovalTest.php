<?php

namespace Tests\Feature;

use App\Enums\WorkOrderAuditEvent;
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

    protected WorkOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderService::class);
    }

    // === Responsable opérationnel ===

    public function test_creation_with_responsible_records_it(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $responsible = User::factory()->create();

        $workOrder = $this->service->create([
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

        $workOrder = $this->service->create([
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

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF responsable modifiable',
            'responsible_id' => $responsible->id,
        ], $user);

        $workOrder = $this->service->update($workOrder, [
            'responsible_id' => $otherResponsible->id,
        ], $user);

        $this->assertSame($otherResponsible->id, $workOrder->responsible_id);
    }

    public function test_responsible_must_be_an_existing_user(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $this->expectException(ValidationException::class);

        $this->service->create([
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

        $workOrder = $this->service->create([
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

        $this->service->update($workOrder, [
            'responsible_id' => $responsible->id,
        ], $user);
    }

    // === Approbation bureau d'études ===

    public function test_be_approval_by_authorized_user(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF à approuver par BE',
        ], $user);

        $workOrder = $this->service->approveByBE($workOrder, $user);

        $this->assertSame($user->id, $workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
        $this->assertInstanceOf(User::class, $workOrder->beApprover);
        $this->assertSame($user->id, $workOrder->beApprover->id);
    }

    public function test_be_approval_by_non_admin_is_rejected(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->create(); // pas admin

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF à approuver',
        ], $user);

        $this->expectException(ValidationException::class);

        $this->service->approveByBE($workOrder, $user);
    }

    public function test_be_approval_is_traced_in_audit_log(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF audit BE',
        ], $user);

        $this->service->approveByBE($workOrder, $user);

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

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF sans approbation BE',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);

        $this->expectException(ValidationException::class);

        $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);
    }

    public function test_release_with_valid_be_approval_is_allowed(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF avec approbation BE',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $this->service->approveByBE($workOrder, $user);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
        $this->assertNotNull($workOrder->released_at);
    }

    public function test_release_still_requires_active_job_with_be_approval(): void
    {
        $job = Job::factory()->create(); // pas active
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF BE avec job inactif',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $this->service->approveByBE($workOrder, $user);

        $this->expectException(ValidationException::class);

        $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);
    }

    public function test_release_rollback_on_audit_failure(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF rollback BE',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $this->service->approveByBE($workOrder, $user);

        $entryCountBefore = WorkOrder::query()->where('id', $workOrder->id)->count();
        $statusBefore = $workOrder->status;

        $dispatcher = app('events');
        $listener = function (): void {
            throw new RuntimeException('Échec simulé de l\'écriture du journal d\'audit.');
        };
        $dispatcher->listen('eloquent.creating: '.WorkOrderAuditLog::class, $listener);

        try {
            $this->expectException(RuntimeException::class);
            $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);
            $this->fail('Une exception aurait dû être levée.');
        } finally {
            $dispatcher->forget('eloquent.creating: '.WorkOrderAuditLog::class);
        }

        // Rollback : le statut n'a pas changé (toujours Ready).
        $this->assertSame($statusBefore, $workOrder->fresh()->status);
        // Rollback : toujours pas Released.
        $this->assertSame(WorkOrderStatus::Ready, $workOrder->fresh()->status);
    }

    public function test_be_approval_is_invalidated_on_work_order_update(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF approbation invalidée',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = $this->service->approveByBE($workOrder, $user);

        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);

        // Une modification invalide l'approbation BE.
        $workOrder = $this->service->update($workOrder, [
            'title' => 'Titre modifié après approbation',
        ], $user);

        $this->assertNull($workOrder->be_approved_by);
        $this->assertNull($workOrder->be_approved_at);
    }

    public function test_new_be_approval_required_after_invalidation(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF réapprobation',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = $this->service->approveByBE($workOrder, $user);

        // Invalidation via modification.
        $workOrder = $this->service->update($workOrder, [
            'title' => 'Titre modifié',
        ], $user);

        $this->assertNull($workOrder->be_approved_by);

        // Une nouvelle approbation est requise avant libération.
        $this->expectException(ValidationException::class);
        $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        // Nouvelle approbation puis libération autorisée.
        $this->service->approveByBE($workOrder, $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
    }

    public function test_be_approval_preserved_on_released_work_order(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF approbation préservée',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $this->service->approveByBE($workOrder, $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        // L'OF est libéré : l'approbation BE et les données sont conservées.
        $this->assertNotNull($workOrder->be_approved_by);
        $this->assertNotNull($workOrder->be_approved_at);
        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
        $this->assertNotNull($workOrder->released_at);
    }

    public function test_be_approver_relation_works(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF relation BE approver',
        ], $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $workOrder = $this->service->approveByBE($workOrder, $user);

        $this->assertInstanceOf(User::class, $workOrder->beApprover);
        $this->assertSame($user->id, $workOrder->beApprover->id);
    }
}
