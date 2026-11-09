<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Services\OperationDependencyService;
use App\Services\WorkOrderRoutingService;
use App\Services\WorkshopRoutingOperationService;
use App\Services\WorkshopRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderRoutingService $service;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderRoutingService::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_an_active_routing_is_assigned_and_all_operations_and_dependencies_are_copied(): void
    {
        $routing = $this->routing(
            'GAM-ASSIGN',
            [
                'DEBIT' => [],
                'PERCAGE' => ['DEBIT'],
                'PREPARATION' => ['DEBIT'],
                'ASSEMBLAGE' => ['PERCAGE', 'PREPARATION'],
            ],
        );

        $workOrder = WorkOrder::factory()->create();

        $assigned = $this->service->assign($workOrder, $routing, $this->admin);

        $this->assertSame($routing->getKey(), $assigned->workshop_routing_id);
        $this->assertSame(4, $assigned->operations()->count());

        $operations = $assigned->operations()->get()->keyBy('code');

        $this->assertSame(
            ['DEBIT', 'PERCAGE', 'PREPARATION', 'ASSEMBLAGE'],
            $assigned->operations()->pluck('code')->all(),
        );
        $this->assertSame([10, 20, 30, 40], $assigned->operations()->pluck('position')->all());

        $this->assertSame('Débit matière', $operations['DEBIT']->name);
        $this->assertSame(30, $operations['DEBIT']->estimated_duration_minutes);
        $this->assertSame(10, $operations['DEBIT']->position);
        $this->assertTrue($operations['DEBIT']->is_mandatory);
        $this->assertSame(WorkshopOperationStatus::Ready, $operations['DEBIT']->status);
        $this->assertSame(WorkshopOperationStatus::Pending, $operations['PERCAGE']->status);

        $this->assertTrue(
            $operations['PERCAGE']->dependencies()->whereKey($operations['DEBIT']->getKey())->exists(),
        );
        $this->assertTrue(
            $operations['PREPARATION']->dependencies()->whereKey($operations['DEBIT']->getKey())->exists(),
        );
        $this->assertSame(
            [$operations['PERCAGE']->getKey(), $operations['PREPARATION']->getKey()],
            $operations['ASSEMBLAGE']->dependencies()
                ->orderBy('work_order_operations.code')
                ->pluck('work_order_operations.id')
                ->all(),
        );

        $this->assertSame(
            $routing->operations()->pluck('id')->sort()->values()->all(),
            $assigned->operations()->pluck('source_routing_operation_id')->sort()->values()->all(),
        );

        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $assigned->getKey(),
            'event' => WorkshopOperationAuditEvent::RoutingApplied->value,
            'user_id' => $this->admin->getKey(),
        ]);
        $this->assertSame(
            4,
            $assigned->operationAuditLogs()
                ->where('event', WorkshopOperationAuditEvent::Created->value)
                ->count(),
        );
    }

    public function test_an_inactive_routing_is_refused(): void
    {
        $routing = $this->routing('GAM-INACTIVE', ['DEBIT' => []]);
        app(WorkshopRoutingService::class)->deactivate($routing);

        $workOrder = WorkOrder::factory()->create();

        $this->assertValidationFails(
            fn () => $this->service->assign($workOrder, $routing->fresh(), $this->admin),
            'routing',
        );

        $this->assertNull($workOrder->fresh()->workshop_routing_id);
        $this->assertSame(0, $workOrder->operations()->count());
    }

    public function test_a_missing_or_invalid_routing_is_refused(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $missing = WorkshopRouting::factory()->make(['id' => 999999]);
        $this->assertValidationFails(
            fn () => $this->service->assign($workOrder, $missing, $this->admin),
            'routing',
        );

        $invalid = WorkshopRouting::factory()->create([
            'code' => 'GAM-EMPTY',
            'name' => 'Gamme vide active',
            'is_active' => true,
        ]);

        $this->assertValidationFails(
            fn () => $this->service->assign($workOrder, $invalid, $this->admin),
            'operations',
        );
    }

    public function test_assignment_checks_the_actor_authorization(): void
    {
        $routing = $this->routing('GAM-PERM', ['DEBIT' => []]);
        $workOrder = WorkOrder::factory()->create();
        $operator = User::factory()->create();

        $this->assertValidationFails(
            fn () => $this->service->assign($workOrder, $routing, $operator),
            'routing',
        );

        $supervisor = User::factory()->workshopSupervisor()->create();
        $assigned = $this->service->assign($workOrder, $routing, $supervisor);

        $this->assertSame($routing->getKey(), $assigned->workshop_routing_id);
    }

    public function test_a_work_order_without_routing_keeps_working_normally(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->assertNull($workOrder->workshop_routing_id);
        $this->assertTrue($workOrder->isRoutingEditable());
        $this->assertSame(0, $workOrder->operations()->count());
    }

    public function test_source_routing_changes_do_not_modify_generated_operations(): void
    {
        $routing = $this->routing('GAM-INDEP', ['DEBIT' => [], 'PERCAGE' => ['DEBIT']]);
        $workOrder = WorkOrder::factory()->create();

        $this->service->assign($workOrder, $routing, $this->admin);

        $generated = $workOrder->operations()->get()->keyBy('code');
        $sourceOperations = $routing->operations()->get()->keyBy('code');

        app(WorkshopRoutingOperationService::class)->update($sourceOperations['DEBIT'], [
            'name' => 'Débit modifié',
            'instructions' => 'Nouvelles consignes',
            'estimated_duration_minutes' => 99,
            'is_mandatory' => false,
        ]);
        app(WorkshopRoutingService::class)->deactivate($routing);

        $this->assertSame('Débit matière', $generated['DEBIT']->fresh()->name);
        $this->assertSame('Consignes de débit', $generated['DEBIT']->fresh()->instructions);
        $this->assertSame(30, $generated['DEBIT']->fresh()->estimated_duration_minutes);
        $this->assertTrue($generated['DEBIT']->fresh()->is_mandatory);

        // La suppression directe de la gamme (hors service, qui la refuse)
        // démontre que les copies restent indépendantes au niveau SQL.
        $routing->delete();

        $workOrder->refresh();
        $this->assertNull($workOrder->workshop_routing_id);
        $this->assertSame(2, $workOrder->operations()->count());
        $this->assertNull($generated['DEBIT']->fresh()->source_routing_operation_id);
        $this->assertSame('Débit matière', $generated['DEBIT']->fresh()->name);
    }

    public function test_a_routing_can_be_replaced_before_release_and_old_operations_are_deleted(): void
    {
        $first = $this->routing('GAM-FIRST', ['DEBIT' => []]);
        $second = $this->routing('GAM-SECOND', ['PERCAGE' => [], 'ASSEMBLAGE' => ['PERCAGE']]);

        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $first, $this->admin);

        $oldOperationIds = $workOrder->operations()->pluck('id')->all();

        $replaced = $this->service->replace($workOrder, $second, $this->admin);

        $this->assertSame($second->getKey(), $replaced->workshop_routing_id);
        $this->assertSame(['PERCAGE', 'ASSEMBLAGE'], $replaced->operations()->pluck('code')->all());

        foreach ($oldOperationIds as $oldOperationId) {
            $this->assertDatabaseMissing('work_order_operations', ['id' => $oldOperationId]);
        }

        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkshopOperationAuditEvent::RoutingChanged->value,
        ]);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkshopOperationAuditEvent::RoutingOperationsDeleted->value,
        ]);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkshopOperationAuditEvent::Created->value,
            'operation_code' => 'DEBIT',
            'work_order_operation_id' => null,
        ]);
    }

    public function test_assignment_is_allowed_on_a_ready_work_order(): void
    {
        $routing = $this->routing('GAM-READY', ['DEBIT' => []]);
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Ready)->create();

        $assigned = $this->service->assign($workOrder, $routing, $this->admin);

        $this->assertSame($routing->getKey(), $assigned->workshop_routing_id);
        $this->assertSame(1, $assigned->operations()->count());
    }

    public function test_replacement_is_refused_when_time_entries_exist(): void
    {
        $first = $this->routing('GAM-TIME', ['DEBIT' => []]);
        $second = $this->routing('GAM-TIME-2', ['PERCAGE' => []]);

        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $first, $this->admin);

        $operation = $workOrder->operations()->firstOrFail();
        OperationTimeEntry::factory()->forOperation($operation)->create();

        $this->assertValidationFails(
            fn () => $this->service->replace($workOrder->fresh(), $second, $this->admin),
            'routing',
        );

        $this->assertSame($first->getKey(), $workOrder->fresh()->workshop_routing_id);
        $this->assertSame($operation->getKey(), $workOrder->operations()->firstOrFail()->getKey());
    }

    public function test_replacement_is_refused_when_operations_have_execution_history(): void
    {
        $first = $this->routing('GAM-HISTORY', ['DEBIT' => []]);
        $second = $this->routing('GAM-HISTORY-2', ['PERCAGE' => []]);

        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $first, $this->admin);

        $operation = $workOrder->operations()->firstOrFail();
        $operation->forceFill([
            'status' => WorkshopOperationStatus::InProgress,
            'started_at' => now(),
        ])->save();

        $this->assertValidationFails(
            fn () => $this->service->replace($workOrder->fresh(), $second, $this->admin),
            'routing',
        );

        $this->assertDatabaseHas('work_order_operations', ['id' => $operation->getKey()]);
    }

    public function test_replacement_failure_rolls_back_both_routing_and_operations(): void
    {
        $first = $this->routing('GAM-ROLLBACK-1', ['DEBIT' => []]);
        $second = app(WorkshopRoutingService::class)->create([
            'code' => 'GAM-ROLLBACK-2',
            'name' => 'Gamme invalide',
            'is_active' => true,
            'operations' => [
                ['code' => 'PERCAGE', 'name' => 'Perçage'],
            ],
        ]);

        // L'activation a validé la gamme ; on la corrompt après coup pour
        // provoquer un échec de validation lors du remplacement.
        $second->operations()->update(['position' => 0]);

        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $first, $this->admin);
        $oldOperationIds = $workOrder->operations()->pluck('id')->all();

        $this->assertValidationFails(
            fn () => $this->service->replace($workOrder->fresh(), $second->fresh(), $this->admin),
            'operations',
        );

        $this->assertSame($first->getKey(), $workOrder->fresh()->workshop_routing_id);
        $this->assertSame($oldOperationIds, $workOrder->operations()->pluck('id')->all());
    }

    public function test_assignment_and_replacement_are_refused_after_release(): void
    {
        $routing = $this->routing('GAM-RELEASED', ['DEBIT' => []]);
        $other = $this->routing('GAM-RELEASED-2', ['PERCAGE' => []]);

        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $this->assertValidationFails(
            fn () => $this->service->assign($workOrder, $routing, $this->admin),
            'routing',
        );

        $workOrderWithRouting = WorkOrder::factory()->create();
        $this->service->assign($workOrderWithRouting, $routing, $this->admin);
        $workOrderWithRouting->forceFill(['status' => WorkOrderStatus::Released])->save();

        $this->assertValidationFails(
            fn () => $this->service->replace($workOrderWithRouting->fresh(), $other, $this->admin),
            'routing',
        );
    }

    public function test_an_operation_can_be_modified_before_release_and_is_locked_after_release(): void
    {
        $routing = $this->routing('GAM-OP-EDIT', ['DEBIT' => []]);
        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $routing, $this->admin);

        $operation = $workOrder->operations()->firstOrFail();

        $updated = $this->service->updateOperation($operation, [
            'name' => 'Débit ajusté',
            'instructions' => 'Consignes ajustées',
            'estimated_duration_minutes' => 45,
            'is_mandatory' => false,
        ], $this->admin);

        $this->assertSame('Débit ajusté', $updated->name);
        $this->assertSame('Consignes ajustées', $updated->instructions);
        $this->assertSame(45, $updated->estimated_duration_minutes);
        $this->assertFalse($updated->is_mandatory);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkshopOperationAuditEvent::Updated->value,
        ]);

        $workOrder->forceFill(['status' => WorkOrderStatus::Released])->save();

        $this->assertValidationFails(
            fn () => $this->service->updateOperation($updated->fresh(), ['name' => 'Interdit'], $this->admin),
            'routing',
        );

        $this->assertSame('Débit ajusté', $updated->fresh()->name);
    }

    public function test_dependencies_can_be_synced_before_release_and_cycles_are_refused(): void
    {
        $routing = $this->routing('GAM-DEPS', ['A' => [], 'B' => [], 'C' => []]);
        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $routing, $this->admin);

        $operations = $workOrder->operations()->get()->keyBy('code');

        $this->service->syncOperationDependencies(
            $operations['B'],
            [$operations['A']->getKey()],
            $this->admin,
        );

        $this->assertTrue($operations['B']->fresh()->dependencies()->whereKey($operations['A']->getKey())->exists());

        $this->assertValidationFails(
            fn () => $this->service->syncOperationDependencies(
                $operations['A']->fresh(),
                [$operations['B']->getKey()],
                $this->admin,
            ),
            'dependencies',
        );

        $this->assertSame([], $operations['A']->fresh()->dependencies()->pluck('work_order_operations.id')->all());
    }

    public function test_an_operation_can_be_deleted_before_release_only_without_history(): void
    {
        $routing = $this->routing('GAM-DELETE', ['A' => [], 'B' => ['A'], 'C' => []]);
        $workOrder = WorkOrder::factory()->create();
        $this->service->assign($workOrder, $routing, $this->admin);

        $operations = $workOrder->operations()->get()->keyBy('code');

        $this->service->deleteOperation($operations['C'], $this->admin);

        $this->assertDatabaseMissing('work_order_operations', ['id' => $operations['C']->getKey()]);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkshopOperationAuditEvent::Deleted->value,
        ]);

        $this->assertValidationFails(
            fn () => $this->service->deleteOperation($operations['A']->fresh(), $this->admin),
            'operation',
        );

        $workOrder->forceFill(['status' => WorkOrderStatus::Released])->save();

        $this->assertValidationFails(
            fn () => $this->service->deleteOperation($operations['B']->fresh(), $this->admin),
            'routing',
        );
    }

    /**
     * @param  array<string, array<int, string>>  $operations  code => dépendances
     */
    protected function routing(string $code, array $operations): WorkshopRouting
    {
        $routingService = app(WorkshopRoutingService::class);
        $operationService = app(WorkshopRoutingOperationService::class);
        $dependencyService = app(OperationDependencyService::class);

        $routing = $routingService->create([
            'code' => $code,
            'name' => "Gamme {$code}",
            'description' => 'Gamme de test',
        ]);

        /** @var array<string, WorkshopRoutingOperation> $created */
        $created = [];

        foreach (array_keys($operations) as $operationCode) {
            $created[$operationCode] = $operationService->create($routing, [
                'code' => $operationCode,
                'name' => $operationCode === 'DEBIT' ? 'Débit matière' : "Opération {$operationCode}",
                'instructions' => $operationCode === 'DEBIT' ? 'Consignes de débit' : null,
                'estimated_duration_minutes' => 30,
                'is_mandatory' => $operationCode !== 'PREPARATION',
            ]);
        }

        foreach ($operations as $operationCode => $dependencies) {
            foreach ($dependencies as $dependencyCode) {
                $dependencyService->add($routing, $created[$operationCode], $created[$dependencyCode]);
            }
        }

        return $routingService->activate($routing);
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
