<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\WorkOrderOperationsRelationManager;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderOperationFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_actions_are_visible_according_to_the_operation_status(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $ready = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'READY');
        $inProgress = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'RUNNING');
        $paused = $this->operation($workOrder, WorkshopOperationStatus::Paused, 'PAUSED');
        $blocked = $this->operation($workOrder, WorkshopOperationStatus::Blocked, 'BLOCKED');
        $blocked->forceFill([
            'blocked_reason' => 'Motif de blocage manuel',
            'blocked_at' => now(),
            'blocked_by' => $admin->getKey(),
        ])->save();

        $this->actingAs($admin);

        $this->manager($workOrder)
            ->assertCanSeeTableRecords([$ready, $inProgress, $paused, $blocked])
            ->assertSee('Motif de blocage manuel')
            ->assertTableActionVisible('startOperation', $ready)
            ->assertTableActionVisible('cancelOperation', $ready)
            ->assertTableActionVisible('pauseOperation', $inProgress)
            ->assertTableActionVisible('blockOperation', $inProgress)
            ->assertTableActionVisible('completeOperation', $inProgress)
            ->assertTableActionVisible('resumeOperation', $paused)
            ->assertTableActionVisible('blockOperation', $paused)
            ->assertTableActionVisible('cancelOperation', $paused)
            ->assertTableActionVisible('unblockOperation', $blocked)
            ->assertTableActionVisible('resumeOperation', $blocked)
            ->assertTableActionVisible('cancelOperation', $blocked)
            ->assertTableActionHidden('startOperation', $inProgress);
    }

    public function test_an_admin_can_run_the_full_operation_cycle_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'CYCLE');

        $this->actingAs($admin);

        $manager = $this->manager($workOrder);

        $manager->callTableAction('startOperation', $operation)
            ->assertNotified('Opération démarrée');
        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);

        $manager->callTableAction('pauseOperation', $operation->fresh())
            ->assertNotified('Opération mise en pause');
        $this->assertSame(WorkshopOperationStatus::Paused, $operation->fresh()->status);

        $manager->callTableAction('resumeOperation', $operation->fresh())
            ->assertNotified('Opération reprise');
        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);

        $manager->callTableAction('blockOperation', $operation->fresh(), data: [
            'reason' => 'Rupture de stock',
        ])->assertNotified('Opération bloquée');

        $this->assertSame(WorkshopOperationStatus::Blocked, $operation->fresh()->status);
        $this->assertSame('Rupture de stock', $operation->fresh()->blocked_reason);

        $manager->callTableAction('unblockOperation', $operation->fresh())
            ->assertNotified('Opération débloquée');
        $this->assertSame(WorkshopOperationStatus::Ready, $operation->fresh()->status);
        $this->assertNull($operation->fresh()->blocked_reason);

        $manager->callTableAction('startOperation', $operation->fresh())
            ->assertNotified('Opération démarrée');

        $manager->callTableAction('completeOperation', $operation->fresh())
            ->assertNotified('Opération terminée');

        $this->assertSame(WorkshopOperationStatus::Completed, $operation->fresh()->status);
        $this->assertNotNull($operation->fresh()->completed_at);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::Completed->value,
        ]);
    }

    public function test_an_admin_can_cancel_an_operation_with_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'CANCEL');

        $this->actingAs($admin);

        $this->manager($workOrder)
            ->callTableAction('cancelOperation', $operation, data: [
                'reason' => 'Opération écartée du périmètre',
            ])
            ->assertNotified('Opération annulée');

        $this->assertSame(WorkshopOperationStatus::Cancelled, $operation->fresh()->status);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::Cancelled->value,
            'comment' => 'Opération écartée du périmètre',
        ]);
    }

    public function test_execution_actions_are_hidden_before_work_order_release(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'DRAFT');

        $this->actingAs($admin);

        $this->manager($workOrder)
            ->assertTableActionHidden('startOperation', $operation)
            ->assertTableActionHidden('pauseOperation', $operation)
            ->assertTableActionHidden('blockOperation', $operation)
            ->assertTableActionHidden('completeOperation', $operation)
            ->assertTableActionHidden('cancelOperation', $operation);
    }

    protected function manager(WorkOrder $workOrder): Testable
    {
        return Livewire::test(WorkOrderOperationsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ]);
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
}
