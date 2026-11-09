<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkshopRoutingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_tables_and_columns_are_present(): void
    {
        foreach ([
            'workshop_routings',
            'workshop_routing_operations',
            'workshop_routing_operation_dependencies',
            'work_order_operations',
            'work_order_operation_dependencies',
            'operation_time_entries',
            'work_order_operation_audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }

        foreach ([
            'code',
            'name',
            'description',
            'is_active',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('workshop_routings', $column), "workshop_routings.{$column} absent.");
        }

        foreach ([
            'workshop_routing_id',
            'code',
            'name',
            'instructions',
            'position',
            'estimated_duration_minutes',
            'is_mandatory',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('workshop_routing_operations', $column),
                "workshop_routing_operations.{$column} absent.",
            );
        }

        foreach ([
            'work_order_id',
            'source_routing_operation_id',
            'code',
            'name',
            'instructions',
            'position',
            'estimated_duration_minutes',
            'is_mandatory',
            'status',
            'blocked_reason',
            'blocked_at',
            'blocked_by',
            'started_at',
            'completed_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_order_operations', $column),
                "work_order_operations.{$column} absent.",
            );
        }

        foreach ([
            'work_order_operation_id',
            'user_id',
            'started_at',
            'ended_at',
            'duration_seconds',
            'entry_type',
            'created_by',
            'corrected_by',
            'correction_reason',
            'notes',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('operation_time_entries', $column),
                "operation_time_entries.{$column} absent.",
            );
        }

        $this->assertTrue(Schema::hasColumn('work_orders', 'workshop_routing_id'));
        $this->assertTrue(Schema::hasColumn('users', 'is_workshop_supervisor'));
    }

    public function test_a_routing_exposes_its_ordered_operations_and_dependency_graph(): void
    {
        $routing = WorkshopRouting::factory()->create();

        $drilling = WorkshopRoutingOperation::factory()
            ->forRouting($routing)
            ->create(['code' => 'PERCAGE', 'position' => 20]);
        $cutting = WorkshopRoutingOperation::factory()
            ->forRouting($routing)
            ->create(['code' => 'DEBIT', 'position' => 10]);

        $drilling->dependencies()->attach($cutting->getKey());

        $operations = $routing->operations()->get();

        $this->assertSame(['DEBIT', 'PERCAGE'], $operations->pluck('code')->all());
        $this->assertTrue($drilling->dependencies()->whereKey($cutting->getKey())->exists());
        $this->assertTrue($cutting->dependents()->whereKey($drilling->getKey())->exists());
        $this->assertCount(1, $drilling->dependencies);
    }

    public function test_a_work_order_can_reference_a_routing_and_copy_operations_independently(): void
    {
        $routing = WorkshopRouting::factory()->create();
        $routingOperation = WorkshopRoutingOperation::factory()
            ->forRouting($routing)
            ->create([
                'code' => 'SOUDURE',
                'name' => 'Soudure',
                'instructions' => 'Soudure d’angle',
                'estimated_duration_minutes' => 45,
                'is_mandatory' => true,
            ]);

        $workOrder = WorkOrder::factory()->create();

        // L'affectation est faite par le service métier (Lot 3) ; la relation
        // et la copie indépendante sont vérifiées dès les fondations.
        $workOrder->forceFill(['workshop_routing_id' => $routing->getKey()])->save();

        $operation = WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'source_routing_operation_id' => $routingOperation->getKey(),
                'code' => $routingOperation->code,
                'name' => $routingOperation->name,
                'instructions' => $routingOperation->instructions,
                'estimated_duration_minutes' => $routingOperation->estimated_duration_minutes,
                'is_mandatory' => $routingOperation->is_mandatory,
                'status' => WorkshopOperationStatus::Pending,
            ]);

        $routingOperation->update([
            'name' => 'Soudure modifiée',
            'instructions' => 'Nouvelles consignes',
        ]);

        $this->assertSame($routing->getKey(), $workOrder->fresh()->workshopRouting->getKey());
        $this->assertSame('Soudure', $operation->fresh()->name);
        $this->assertSame('Soudure d’angle', $operation->fresh()->instructions);
        $this->assertSame(45, $operation->fresh()->estimated_duration_minutes);
        $this->assertSame(WorkshopOperationStatus::Pending, $operation->fresh()->status);
    }

    public function test_deleting_a_routing_preserves_generated_work_order_operations(): void
    {
        $routing = WorkshopRouting::factory()->create();
        $routingOperation = WorkshopRoutingOperation::factory()->forRouting($routing)->create();

        $operation = WorkOrderOperation::factory()->create([
            'source_routing_operation_id' => $routingOperation->getKey(),
        ]);

        $routing->delete();

        $operation->refresh();

        $this->assertNull($operation->source_routing_operation_id);
        $this->assertDatabaseHas('work_order_operations', ['id' => $operation->getKey()]);
    }

    public function test_routing_editability_follows_the_work_order_lifecycle(): void
    {
        $draft = WorkOrder::factory()->create();
        $ready = WorkOrder::factory()->status(WorkOrderStatus::Ready)->create();
        $released = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $completed = WorkOrder::factory()->completed()->create();

        $this->assertTrue($draft->isRoutingEditable());
        $this->assertTrue($ready->isRoutingEditable());
        $this->assertFalse($released->isRoutingEditable());
        $this->assertFalse($completed->isRoutingEditable());
        $this->assertTrue($released->isRoutingLocked());
    }

    public function test_operation_dependencies_drive_start_availability(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $cutting = WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create(['code' => 'DEBIT', 'status' => WorkshopOperationStatus::Completed]);
        $drilling = WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create(['code' => 'PERCAGE', 'status' => WorkshopOperationStatus::Ready]);

        $drilling->dependencies()->attach($cutting->getKey());

        $this->assertTrue($drilling->fresh()->dependenciesSatisfied());
        $this->assertTrue($drilling->fresh()->canStart());

        $cutting->forceFill(['status' => WorkshopOperationStatus::InProgress])->save();

        $this->assertFalse($drilling->fresh()->dependenciesSatisfied());
        $this->assertFalse($drilling->fresh()->canStart());
    }

    public function test_a_user_can_be_a_workshop_supervisor_without_admin_access(): void
    {
        $user = User::factory()->workshopSupervisor()->create();

        $this->assertTrue($user->isWorkshopSupervisor());
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->canManageWorkshopRoutings());
        $this->assertTrue($user->canUnblockWorkshopOperations());
        $this->assertTrue($user->canManageOperationTimes());
    }
}
