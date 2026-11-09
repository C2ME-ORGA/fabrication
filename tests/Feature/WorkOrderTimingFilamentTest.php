<?php

namespace Tests\Feature;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\WorkOrderOperationsRelationManager;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimeEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderTimingFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_timer_can_be_started_and_stopped_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $this->actingAs($admin);

        $manager = $this->manager($workOrder);

        $manager
            ->assertTableActionVisible('startTimer', $operation)
            ->callTableAction('startTimer', $operation)
            ->assertNotified('Chrono démarré');

        $entry = OperationTimeEntry::query()->whereNull('ended_at')->firstOrFail();

        $this->assertSame($operation->getKey(), $entry->work_order_operation_id);
        $this->assertSame($admin->getKey(), $entry->user_id);
        $this->assertSame(OperationTimeEntryType::Timer, $entry->entry_type);

        $manager
            ->assertTableActionVisible('stopTimer', $operation->fresh())
            ->callTableAction('stopTimer', $operation->fresh())
            ->assertNotified('Chrono arrêté');

        $entry->refresh();

        $this->assertNotNull($entry->ended_at);
        $this->assertNotNull($entry->duration_seconds);
    }

    public function test_an_admin_can_record_a_manual_time_entry_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $this->actingAs($admin);

        $this->manager($workOrder)
            ->callTableAction('recordManualTime', data: [
                'operation_id' => $operation->getKey(),
                'user_id' => $operator->getKey(),
                'duration_seconds' => 600,
                'notes' => 'Relevé terrain',
            ])
            ->assertNotified('Temps manuel enregistré');

        $entry = OperationTimeEntry::query()->firstOrFail();

        $this->assertSame(OperationTimeEntryType::Manual, $entry->entry_type);
        $this->assertSame(600, $entry->duration_seconds);
        $this->assertSame($operator->getKey(), $entry->user_id);
        $this->assertSame($admin->getKey(), $entry->created_by);
    }

    public function test_an_admin_can_correct_a_time_entry_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $original = app(OperationTimeEntryService::class)->recordManual(
            $operation,
            $operator,
            600,
            null,
            'Saisie initiale',
            $admin,
        );

        $this->actingAs($admin);

        $this->manager($workOrder->fresh())
            ->assertTableActionVisible('correctTime', $operation)
            ->callTableAction('correctTime', $operation, data: [
                'time_entry_id' => $original->getKey(),
                'corrected_duration_seconds' => 420,
                'correction_reason' => 'Retrait d\'une pause',
            ])
            ->assertNotified('Temps corrigé');

        $correction = OperationTimeEntry::query()
            ->where('entry_type', OperationTimeEntryType::Adjustment->value)
            ->firstOrFail();

        $this->assertSame($original->getKey(), $correction->original_entry_id);
        $this->assertSame(420, $correction->duration_seconds);
        $this->assertSame(600, $original->fresh()->duration_seconds);
    }

    public function test_timer_start_is_hidden_without_an_active_execution_context(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress);

        $this->actingAs($admin);

        $this->manager($workOrder)
            ->assertTableActionHidden('startTimer', $operation)
            ->assertTableActionHidden('recordManualTime');
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
    ): WorkOrderOperation {
        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'code' => 'TIMING',
                'name' => 'Opération chronométrée',
                'status' => $status,
                'started_at' => $status === WorkshopOperationStatus::InProgress ? now() : null,
            ]);
    }
}
