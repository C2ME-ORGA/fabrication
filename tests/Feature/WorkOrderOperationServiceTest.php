<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationDependencyService;
use App\Services\WorkOrderOperationService;
use App\Services\WorkOrderRoutingService;
use App\Services\WorkshopRoutingOperationService;
use App\Services\WorkshopRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderOperationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderOperationService $service;

    protected User $admin;

    protected User $supervisor;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderOperationService::class);
        $this->admin = User::factory()->admin()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->operator = User::factory()->create();
    }

    public function test_a_ready_operation_can_be_started_by_an_operator(): void
    {
        [$workOrder, $operations] = $this->releasedGraph(['A' => []]);

        $operation = $this->service->start($operations['A'], $this->operator);

        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->status);
        $this->assertNotNull($operation->started_at);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::Started->value,
            'user_id' => $this->operator->getKey(),
            'from_status' => WorkshopOperationStatus::Ready->value,
            'to_status' => WorkshopOperationStatus::InProgress->value,
        ]);
    }

    public function test_a_pending_operation_cannot_be_started(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => ['A']]);

        $this->assertSame(WorkshopOperationStatus::Pending, $operations['B']->status);

        $this->assertValidationFails(
            fn () => $this->service->start($operations['B'], $this->operator),
            'status',
        );

        $this->assertSame(WorkshopOperationStatus::Pending, $operations['B']->fresh()->status);
    }

    public function test_an_operation_of_a_non_released_work_order_cannot_be_started(): void
    {
        [, $operations] = $this->releasedGraph(['A' => []], release: false);

        $this->assertValidationFails(
            fn () => $this->service->start($operations['A'], $this->operator),
            'status',
        );

        $this->assertSame(WorkshopOperationStatus::Ready, $operations['A']->fresh()->status);
    }

    public function test_an_operation_can_be_paused_and_resumed(): void
    {
        [$workOrder, $operations] = $this->releasedGraph(['A' => []]);

        $operation = $this->service->start($operations['A'], $this->operator);
        $operation = $this->service->pause($operation, $this->operator);

        $this->assertSame(WorkshopOperationStatus::Paused, $operation->status);

        $operation = $this->service->resume($operation, $this->operator);

        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->status);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::Paused->value,
        ]);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::Resumed->value,
        ]);
    }

    public function test_blocking_requires_a_non_blank_reason_and_keeps_the_actor(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => []]);

        $inProgress = $this->service->start($operations['A'], $this->operator);
        $fromPaused = $this->service->pause($this->service->start($operations['B'], $this->operator), $this->operator);

        foreach (['   ', ''] as $invalidReason) {
            $this->assertValidationFails(
                fn () => $this->service->block($inProgress, $this->operator, $invalidReason),
                'blocked_reason',
            );
        }

        $blocked = $this->service->block($inProgress, $this->operator, '  Pièce manquante  ');

        $this->assertSame(WorkshopOperationStatus::Blocked, $blocked->status);
        $this->assertSame('Pièce manquante', $blocked->blocked_reason);
        $this->assertNotNull($blocked->blocked_at);
        $this->assertSame($this->operator->getKey(), $blocked->blocked_by);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $blocked->getKey(),
            'event' => WorkshopOperationAuditEvent::Blocked->value,
            'comment' => 'Pièce manquante',
        ]);

        $blockedFromPaused = $this->service->block($fromPaused, $this->operator, 'Attente outillage');
        $this->assertSame(WorkshopOperationStatus::Blocked, $blockedFromPaused->status);
    }

    public function test_unblocking_recalculates_ready_or_pending(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => ['A']]);

        $a = $this->service->start($operations['A'], $this->operator);
        $this->service->complete($a, $this->operator);

        $b = $operations['B']->fresh();
        $this->assertSame(WorkshopOperationStatus::Ready, $b->status);

        $b = $this->service->start($b, $this->operator);
        $b = $this->service->block($b, $this->operator, 'Contrôle en attente');
        $unblocked = $this->service->unblock($b, $this->supervisor);

        $this->assertSame(WorkshopOperationStatus::Ready, $unblocked->status);
        $this->assertNull($unblocked->blocked_reason);
        $this->assertNull($unblocked->blocked_at);
        $this->assertNull($unblocked->blocked_by);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $unblocked->getKey(),
            'event' => WorkshopOperationAuditEvent::Unblocked->value,
            'user_id' => $this->supervisor->getKey(),
        ]);

        // Cas pending : on force un blocage alors que la dépendance n'est pas
        // terminée, puis le déblocage doit rendre l'opération pending.
        [, $pendingOperations] = $this->releasedGraph(['X' => [], 'Y' => ['X']]);

        $pendingOperations['Y']->forceFill([
            'status' => WorkshopOperationStatus::Blocked,
            'blocked_reason' => 'Blocage forcé',
            'blocked_at' => now(),
            'blocked_by' => $this->operator->getKey(),
        ])->save();

        $unblockedPending = $this->service->unblock($pendingOperations['Y']->fresh(), $this->supervisor);

        $this->assertSame(WorkshopOperationStatus::Pending, $unblockedPending->status);
    }

    public function test_an_operator_cannot_unblock_or_cancel(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => []]);

        $blocked = $this->service->block(
            $this->service->start($operations['A'], $this->operator),
            $this->operator,
            'Bloquée',
        );

        $this->assertValidationFails(
            fn () => $this->service->unblock($blocked, $this->operator),
            'operation',
        );

        $this->assertValidationFails(
            fn () => $this->service->cancel($operations['B'], $this->operator, 'Annulation'),
            'operation',
        );
    }

    public function test_an_operation_can_be_cancelled_from_ready_in_progress_paused_and_blocked(): void
    {
        [$workOrder, $operations] = $this->releasedGraph(['A' => [], 'B' => [], 'C' => [], 'D' => []]);

        $inProgress = $this->service->start($operations['D'], $this->operator);
        $paused = $this->service->pause($this->service->start($operations['B'], $this->operator), $this->operator);
        $blocked = $this->service->block($this->service->start($operations['C'], $this->operator), $this->operator, 'Bloquée');

        $this->assertValidationFails(
            fn () => $this->service->cancel($operations['A'], $this->admin, '   '),
            'cancel_reason',
        );

        foreach ([$operations['A'], $inProgress, $paused, $blocked] as $index => $operation) {
            $cancelled = $this->service->cancel($operation->fresh(), $this->admin, "Annulation {$index}");

            $this->assertSame(WorkshopOperationStatus::Cancelled, $cancelled->status);
            $this->assertDatabaseHas('work_order_operation_audit_logs', [
                'work_order_id' => $workOrder->getKey(),
                'work_order_operation_id' => $cancelled->getKey(),
                'event' => WorkshopOperationAuditEvent::Cancelled->value,
                'user_id' => $this->admin->getKey(),
            ]);
        }
    }

    public function test_cancelling_an_in_progress_operation_requires_no_active_timer(): void
    {
        [, $operations] = $this->releasedGraph(['A' => []]);

        $operation = $this->service->start($operations['A'], $this->operator);

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($this->operator)
            ->create();

        $this->assertValidationFails(
            fn () => $this->service->cancel($operation->fresh(), $this->admin, 'Annulation avec chrono'),
            'time_entries',
        );

        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);
    }

    public function test_completion_propagates_ready_through_parallel_and_multiple_dependencies(): void
    {
        [$workOrder, $operations] = $this->releasedGraph([
            'A' => [],
            'B' => ['A'],
            'C' => ['A'],
            'D' => ['B', 'C'],
        ]);

        $this->assertComplete($operations['A']);

        $this->assertSame(WorkshopOperationStatus::Ready, $operations['B']->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::Ready, $operations['C']->fresh()->status);
        $this->assertSame(WorkshopOperationStatus::Pending, $operations['D']->fresh()->status);

        $this->assertComplete($operations['B']->fresh());
        $this->assertSame(WorkshopOperationStatus::Pending, $operations['D']->fresh()->status);

        $this->assertComplete($operations['C']->fresh());

        $this->assertSame(WorkshopOperationStatus::Ready, $operations['D']->fresh()->status);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'work_order_operation_id' => $operations['D']->getKey(),
            'event' => WorkshopOperationAuditEvent::Ready->value,
            'to_status' => WorkshopOperationStatus::Ready->value,
        ]);
    }

    public function test_completion_is_refused_with_an_active_time_entry(): void
    {
        [, $operations] = $this->releasedGraph(['A' => []]);

        $operation = $this->service->start($operations['A'], $this->operator);

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($this->operator)
            ->create();

        $this->assertValidationFails(
            fn () => $this->service->complete($operation->fresh(), $this->operator),
            'time_entries',
        );

        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);
    }

    public function test_pause_and_block_stop_active_time_entries_while_cancel_refuses_them(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => [], 'C' => []]);

        $pausedOperation = $this->service->start($operations['A'], $this->operator);
        $firstEntry = OperationTimeEntry::factory()
            ->active()
            ->forOperation($pausedOperation)
            ->forUser($this->operator)
            ->create();

        $paused = $this->service->pause($pausedOperation, $this->operator);

        $this->assertSame(WorkshopOperationStatus::Paused, $paused->status);
        $this->assertNotNull($firstEntry->fresh()->ended_at);
        $this->assertNotNull($firstEntry->fresh()->duration_seconds);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $paused->getKey(),
            'event' => WorkshopOperationAuditEvent::TimerAutoStopped->value,
        ]);

        $blockedOperation = $this->service->start($operations['B'], $this->operator);
        $secondEntry = OperationTimeEntry::factory()
            ->active()
            ->forOperation($blockedOperation)
            ->forUser($this->operator)
            ->create();

        $blocked = $this->service->block($blockedOperation, $this->operator, 'Blocage avec chrono');

        $this->assertSame(WorkshopOperationStatus::Blocked, $blocked->status);
        $this->assertNotNull($secondEntry->fresh()->ended_at);
        $this->assertNotNull($secondEntry->fresh()->duration_seconds);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $blocked->getKey(),
            'event' => WorkshopOperationAuditEvent::TimerAutoStopped->value,
        ]);

        $cancelOperation = $this->service->start($operations['C'], $this->operator);
        $cancelOperation->forceFill(['status' => WorkshopOperationStatus::Paused])->save();
        OperationTimeEntry::factory()
            ->active()
            ->forOperation($cancelOperation)
            ->forUser(User::factory()->create())
            ->create();

        $this->assertValidationFails(fn () => $this->service->cancel($cancelOperation, $this->admin, 'Motif'), 'time_entries');
    }

    public function test_a_cancelled_dependency_does_not_make_dependents_ready(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => ['A']]);

        $cancelled = $this->service->cancel($operations['A'], $this->admin, 'Opération écartée');

        $this->assertSame(WorkshopOperationStatus::Cancelled, $cancelled->status);
        $this->assertSame(WorkshopOperationStatus::Pending, $operations['B']->fresh()->status);

        $this->assertValidationFails(
            fn () => $this->service->start($operations['B']->fresh(), $this->operator),
            'status',
        );
    }

    public function test_completed_and_cancelled_operations_cannot_be_reopened(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => []]);

        $completed = $this->assertComplete($operations['A']);
        $cancelled = $this->service->cancel($operations['B'], $this->admin, 'Écartée');

        $this->assertValidationFails(fn () => $this->service->start($completed->fresh(), $this->operator), 'status');
        $this->assertValidationFails(fn () => $this->service->start($cancelled->fresh(), $this->operator), 'status');
    }

    public function test_a_blocked_operation_is_not_recalculated_by_dependency_propagation(): void
    {
        [, $operations] = $this->releasedGraph(['A' => [], 'B' => ['A']]);

        $operations['B']->forceFill([
            'status' => WorkshopOperationStatus::Blocked,
            'blocked_reason' => 'Bloquée manuellement',
            'blocked_at' => now(),
            'blocked_by' => $this->operator->getKey(),
        ])->save();

        $this->assertComplete($operations['A']);

        $this->assertSame(WorkshopOperationStatus::Blocked, $operations['B']->fresh()->status);
        $this->assertFalse(app(OperationDependencyService::class)->recalculate($operations['B']->fresh(), $this->admin));
    }

    public function test_two_successive_starts_only_validate_once(): void
    {
        [, $operations] = $this->releasedGraph(['A' => []]);

        $started = $this->service->start($operations['A'], $this->operator);

        $this->assertValidationFails(
            fn () => $this->service->start($started->fresh(), $this->operator),
            'status',
        );

        $this->assertSame(WorkshopOperationStatus::InProgress, $started->fresh()->status);
    }

    public function test_blocked_resume_is_reserved_to_managers(): void
    {
        [, $operations] = $this->releasedGraph(['A' => []]);

        $blocked = $this->service->block(
            $this->service->start($operations['A'], $this->operator),
            $this->operator,
            'Bloquée',
        );

        $this->assertValidationFails(
            fn () => $this->service->resume($blocked, $this->operator),
            'operation',
        );

        $resumed = $this->service->resume($blocked->fresh(), $this->supervisor);

        $this->assertSame(WorkshopOperationStatus::InProgress, $resumed->status);
        $this->assertNull($resumed->blocked_reason);
        $this->assertNull($resumed->blocked_at);
        $this->assertNull($resumed->blocked_by);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $ready = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'INVALID-READY');
        $inProgress = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'INVALID-RUNNING');
        $paused = $this->operation($workOrder, WorkshopOperationStatus::Paused, 'INVALID-PAUSED');
        $completed = $this->operation($workOrder, WorkshopOperationStatus::Completed, 'INVALID-DONE');
        $cancelled = $this->operation($workOrder, WorkshopOperationStatus::Cancelled, 'INVALID-CANCELLED');

        $checks = [
            fn () => $this->service->pause($ready, $this->operator),
            fn () => $this->service->pause($paused, $this->operator),
            fn () => $this->service->resume($ready, $this->operator),
            fn () => $this->service->block($ready, $this->operator, 'Motif'),
            fn () => $this->service->unblock($inProgress, $this->admin),
            fn () => $this->service->complete($ready, $this->operator),
            fn () => $this->service->complete($completed, $this->operator),
            fn () => $this->service->start($completed, $this->operator),
            fn () => $this->service->start($cancelled, $this->operator),
        ];

        foreach ($checks as $check) {
            $this->assertValidationFails($check, 'status');
        }
    }

    /**
     * @param  array<string, array<int, string>>  $graph
     * @return array{0: WorkOrder, 1: array<string, WorkOrderOperation>}
     */
    protected function releasedGraph(array $graph, bool $release = true): array
    {
        $routingService = app(WorkshopRoutingService::class);
        $operationService = app(WorkshopRoutingOperationService::class);
        $dependencyService = app(OperationDependencyService::class);

        $routing = $routingService->create([
            'code' => 'GAM-'.mb_strtoupper((string) str()->random(8)),
            'name' => 'Gamme de test',
        ]);

        $created = [];

        foreach (array_keys($graph) as $code) {
            $created[$code] = $operationService->create($routing, [
                'code' => $code,
                'name' => "Opération {$code}",
                'estimated_duration_minutes' => 15,
            ]);
        }

        foreach ($graph as $code => $dependencies) {
            foreach ($dependencies as $dependency) {
                $dependencyService->add($routing, $created[$code], $created[$dependency]);
            }
        }

        $routing = $routingService->activate($routing);
        $workOrder = WorkOrder::factory()->create();

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing, $this->admin);

        if ($release) {
            $workOrder->forceFill(['status' => WorkOrderStatus::Released])->save();
        }

        $workOrder->refresh();

        /** @var array<string, WorkOrderOperation> $operations */
        $operations = $workOrder->operations()->get()->keyBy('code')->all();

        return [$workOrder, $operations];
    }

    protected function assertComplete(WorkOrderOperation $operation): WorkOrderOperation
    {
        $started = $this->service->start($operation, $this->operator);

        return $this->service->complete($started, $this->operator);
    }

    protected function operation(
        WorkOrder $workOrder,
        WorkshopOperationStatus $status,
        string $code,
    ): WorkOrderOperation {
        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'code' => $code,
                'name' => "Opération {$code}",
                'status' => $status,
            ]);
    }

    protected function assertValidationFails(callable $callback, ?string $field = null): void
    {
        try {
            $callback();
            $this->fail('Une ValidationException était attendue.');
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }
}
