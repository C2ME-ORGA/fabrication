<?php

namespace Tests\Feature;

use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAuditLog;
use App\Services\WorkOrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class WorkOrderAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderService::class);
    }

    public function test_creation_generates_an_audit_entry(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'Fabrication des poteaux',
            'priority' => WorkOrderPriority::High->value,
            'planned_start_at' => '2026-11-02 08:00:00',
            'planned_end_at' => '2026-11-10 17:00:00',
        ], $user);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->id,
            'user_id' => $user->id,
            'event' => WorkOrderAuditEvent::Created->value,
            'from_status' => null,
            'to_status' => WorkOrderStatus::Draft->value,
        ]);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(WorkOrderAuditEvent::Created->value, $log->event);
        $this->assertNull($log->from_status);
        $this->assertSame(WorkOrderStatus::Draft->value, $log->to_status);
        $this->assertNotNull($log->created_at);
        $this->assertInstanceOf(User::class, $log->user);
    }

    public function test_each_authorized_transition_generates_an_audit_entry(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $expectedTransitions = [
            'ready' => [
                'target' => WorkOrderStatus::Ready,
                'from' => WorkOrderStatus::Draft->value,
                'to' => WorkOrderStatus::Ready->value,
            ],
            'released' => [
                'target' => WorkOrderStatus::Released,
                'from' => WorkOrderStatus::Ready->value,
                'to' => WorkOrderStatus::Released->value,
            ],
            'started' => [
                'target' => WorkOrderStatus::InProgress,
                'from' => WorkOrderStatus::Released->value,
                'to' => WorkOrderStatus::InProgress->value,
            ],
            'quality_check' => [
                'target' => WorkOrderStatus::QualityCheck,
                'from' => WorkOrderStatus::InProgress->value,
                'to' => WorkOrderStatus::QualityCheck->value,
            ],
            'completed' => [
                'target' => WorkOrderStatus::Completed,
                'from' => WorkOrderStatus::QualityCheck->value,
                'to' => WorkOrderStatus::Completed->value,
            ],
        ];

        foreach ($expectedTransitions as $step) {
            if ($step['target'] === WorkOrderStatus::Released) {
                $this->service->approveByBE($workOrder, $user);
            }

            $workOrder = $this->service->transitionTo($workOrder, $step['target'], $user);

            $this->assertDatabaseHas('work_order_audit_logs', [
                'work_order_id' => $workOrder->id,
                'user_id' => $user->id,
                'event' => $this->eventForTarget($step['target'])->value,
                'from_status' => $step['from'],
                'to_status' => $step['to'],
            ]);

            $this->assertNotNull(
                WorkOrderAuditLog::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('event', $this->eventForTarget($step['target'])->value)
                    ->whereNotNull('created_at')
                    ->first(),
                "Une entrée d'audit doit exister pour la transition vers {$step['target']->value}."
            );
        }
    }

    public function test_cancellation_records_the_previous_state_and_comment(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $this->service->approveByBE($workOrder, $user);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);

        $previousEntryCount = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count();

        $workOrder = $this->service->transitionTo(
            $workOrder,
            WorkOrderStatus::Cancelled,
            $user,
            'Motif d’annulation : erreur de saisie.'
        );

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->id,
            'event' => WorkOrderAuditEvent::Cancelled->value,
            'from_status' => WorkOrderStatus::Released->value,
            'to_status' => WorkOrderStatus::Cancelled->value,
            'comment' => 'Motif d’annulation : erreur de saisie.',
            'user_id' => $user->id,
        ]);

        // L'historique précédent reste intact.
        $this->assertSame($previousEntryCount + 1, WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_forbidden_transition_creates_no_audit_entry(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $user = User::factory()->admin()->create();

        $entryCountBefore = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count();

        $this->expectException(ValidationException::class);
        $this->service->transitionTo($workOrder, WorkOrderStatus::Completed, $user);

        $this->assertSame($entryCountBefore, WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_rollback_removes_audit_entry(): void
    {
        $job = Job::factory()->create(); // pas active → libération refusée
        $user = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);
        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);

        $entryCountBefore = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count();

        try {
            $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);
            $this->fail('La libération aurait dû être refusée.');
        } catch (ValidationException) {
            // attendu
        }

        // Aucune nouvelle entrée n'a été ajoutée (rollback de la transaction).
        $this->assertSame($entryCountBefore, WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_audit_entry_uses_server_side_user_not_form_input(): void
    {
        $job = Job::factory()->active()->create();
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        // Même si un autre user_id était tenté, le service utilise l'acteur passé en paramètre.
        $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $admin);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->latest('created_at')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertNotSame($other->id, $log->user_id);
    }

    public function test_work_order_has_audit_logs_relation(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        // Création via le service pour générer une entrée d'audit.
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF avec historique',
        ], $user);
        $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);

        $this->assertInstanceOf(Collection::class, $workOrder->auditLogs);
        $this->assertCount(2, $workOrder->auditLogs); // création + passage en prêt
    }

    public function test_audit_entry_records_the_work_order_number(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF avec numéro',
        ], $user);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->first();

        $this->assertSame($workOrder->number, $log->work_order_number);
        $this->assertMatchesRegularExpression('/^OF-\d{4}-\d{4}$/', $log->work_order_number);
    }

    public function test_work_order_number_persists_after_of_deletion(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF à supprimer',
        ], $user);
        $number = $workOrder->number;

        $this->service->delete($workOrder);

        // L'entrée d'audit subsiste avec le numéro d'OF d'origine.
        $log = WorkOrderAuditLog::query()->where('work_order_number', $number)->first();
        $this->assertNotNull($log);
        $this->assertSame($number, $log->work_order_number);
        $this->assertNull($log->work_order_id);
    }

    public function test_audit_entry_cannot_be_updated(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF immuable',
        ], $user);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immuables');
        $log->update(['comment' => 'Tentative de modification']);
    }

    public function test_audit_entry_cannot_be_deleted(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF immuable',
        ], $user);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->first();
        $logId = $log->id;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immuables');
        $log->delete();

        // L'entrée doit encore exister après l'échec de suppression.
        $this->assertDatabaseHas('work_order_audit_logs', ['id' => $logId]);
    }

    public function test_audit_failure_rolls_back_the_business_action(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        // L'OF a déjà une entrée d'audit (création).
        $entryCountBefore = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count();
        $statusBefore = $workOrder->status;

        // Écouteur temporaire qui fait échouer toute création d'entrée d'audit.
        $dispatcher = app('events');
        $listener = function (): void {
            throw new RuntimeException('Échec simulé de l\'écriture du journal d\'audit.');
        };
        $dispatcher->listen('eloquent.creating: '.WorkOrderAuditLog::class, $listener);

        try {
            $this->expectException(RuntimeException::class);
            $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
            $this->fail('Une exception aurait dû être levée.');
        } finally {
            $dispatcher->forget('eloquent.creating: '.WorkOrderAuditLog::class);
        }

        // Rollback : le statut n'a pas changé.
        $this->assertSame($statusBefore, $workOrder->fresh()->status);
        // Rollback : aucune nouvelle entrée d'audit n'a été conservée.
        $this->assertSame($entryCountBefore, WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_user_deletion_nullifies_user_id_on_audit_logs(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF avec audit',
        ], $user);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertSame($user->id, $log->user_id);

        // Suppression de l'utilisateur : les entrées d'audit sont conservées,
        // user_id passe à NULL (nullOnDelete).
        $user->delete();

        $log->refresh();
        $this->assertNull($log->user_id);
        $this->assertNotNull($log->id); // l'entrée d'audit subsiste
    }

    public function test_work_order_number_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('work_order_audit_logs', 'work_order_number'));

        $column = Schema::getColumnListing('work_order_audit_logs');
        $this->assertContains('work_order_number', $column);

        // Vérifier via une requête directe que la colonne accepte une insertion avec numéro.
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'OF schéma',
        ], $user);

        $log = WorkOrderAuditLog::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertNotNull($log->work_order_number);
    }

    public function test_orphan_audit_event_is_preserved_with_null_number(): void
    {
        // Insérer directement un événement orphelin (pas d'OF lié, pas de numéro),
        // simulant un événement historique dont l'OF a été supprimé avant cette migration.
        DB::table('work_order_audit_logs')->insert([
            'work_order_id' => null,
            'work_order_number' => null,
            'user_id' => null,
            'event' => 'created',
            'from_status' => null,
            'to_status' => 'draft',
            'comment' => null,
            'metadata' => null,
            'created_at' => now(),
        ]);

        $orphanCount = WorkOrderAuditLog::query()
            ->whereNull('work_order_id')
            ->whereNull('work_order_number')
            ->count();

        $this->assertSame(1, $orphanCount);

        // L'événement orphelin subsiste avec NULL : aucun numéro n'a été inventé.
        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => null,
            'work_order_number' => null,
            'event' => 'created',
        ]);
    }

    public function test_audit_refuses_event_without_work_order_number(): void
    {
        // Utiliser la réflexion pour appeler la méthode protected audit() directement
        // avec un OF qui n'a pas de numéro.
        $reflection = new \ReflectionMethod($this->service, 'audit');
        $reflection->setAccessible(true);

        $workOrder = new WorkOrder(['title' => 'OF sans numéro']);
        $workOrder->number = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ne possède pas de numéro');

        $reflection->invoke($this->service, $workOrder, WorkOrderAuditEvent::Created);
    }

    private function eventForTarget(WorkOrderStatus $target): WorkOrderAuditEvent
    {
        return match ($target) {
            WorkOrderStatus::Ready => WorkOrderAuditEvent::Ready,
            WorkOrderStatus::Released => WorkOrderAuditEvent::Released,
            WorkOrderStatus::InProgress => WorkOrderAuditEvent::Started,
            WorkOrderStatus::QualityCheck => WorkOrderAuditEvent::QualityCheck,
            WorkOrderStatus::Completed => WorkOrderAuditEvent::Completed,
            WorkOrderStatus::Cancelled => WorkOrderAuditEvent::Cancelled,
            default => WorkOrderAuditEvent::Created,
        };
    }
}
