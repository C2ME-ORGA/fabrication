<?php

namespace Tests\Feature;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Filament\Workshop\Resources\WorkshopOperations\Pages\ListWorkshopOperations;
use App\Filament\Workshop\Resources\WorkshopOperations\Pages\ViewWorkshopOperation;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\WorkOrderOperationAuditService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkshopOperationsFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected ?Panel $previousPanel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel($this->previousPanel);

        parent::tearDown();
    }

    public function test_the_central_list_shows_only_active_work_orders_and_computes_times(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $job = $workOrder->job;

        $visible = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-VISIBLE', 120);
        OperationTimeEntry::factory()
            ->forOperation($visible)
            ->forUser($operator)
            ->create(['duration_seconds' => 3600]);

        $draftWorkOrder = WorkOrder::factory()->create();
        $hidden = $this->operation($draftWorkOrder, WorkshopOperationStatus::InProgress, 'OP-DRAFT');

        $this->actingAs($operator)
            ->get('/workshop/operations')
            ->assertOk()
            ->assertSee($workOrder->number)
            ->assertSee('OP-VISIBLE')
            ->assertDontSee($draftWorkOrder->number)
            ->assertDontSee('OP-DRAFT');

        Livewire::test(ListWorkshopOperations::class)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden])
            ->assertSee('1 h')
            ->searchTable('OP-VISIBLE')
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden])
            ->searchTable(null)
            ->filterTable('status', WorkshopOperationStatus::InProgress->value)
            ->assertCanSeeTableRecords([$visible])
            ->resetTableFilters()
            ->filterTable('job_id', $job->getKey())
            ->assertCanSeeTableRecords([$visible]);
    }

    public function test_the_list_can_filter_active_timers_and_paginates(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $active = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'ACTIVE-TIMER');
        OperationTimeEntry::factory()
            ->active()
            ->forOperation($active)
            ->forUser($operator)
            ->create();

        $inactive = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'NO-TIMER');

        $this->actingAs($operator);

        Livewire::test(ListWorkshopOperations::class)
            ->filterTable('active_timer', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);

        $many = collect(range(1, 26))->map(
            fn (int $index): WorkOrderOperation => $this->operation(
                $workOrder,
                WorkshopOperationStatus::Ready,
                sprintf('PAGE-%03d', $index),
            ),
        );

        $all = collect([$active, $inactive])->merge($many)->sortBy('code')->values();

        Livewire::test(ListWorkshopOperations::class)
            ->resetTableFilters()
            ->sortTable('code')
            ->assertCountTableRecords(28)
            ->assertCanSeeTableRecords($all->take(25))
            ->assertCanNotSeeTableRecords([$all->last()])
            ->call('setPage', 2)
            ->assertCanSeeTableRecords([$all->last()]);
    }

    public function test_operator_actions_are_filtered_by_policy_and_timer_actions_work(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-ACTION');

        $this->actingAs($operator);

        Livewire::test(ListWorkshopOperations::class)
            ->assertTableActionVisible('pauseOperation', $operation)
            ->assertTableActionVisible('completeOperation', $operation)
            ->assertTableActionVisible('startTimer', $operation)
            ->assertTableActionHidden('cancelOperation', $operation)
            ->assertTableActionHidden('unblockOperation', $operation)
            ->callTableAction('startTimer', $operation)
            ->assertNotified('Chrono démarré');

        $entry = OperationTimeEntry::query()->whereNull('ended_at')->firstOrFail();
        $this->assertSame($operation->getKey(), $entry->work_order_operation_id);

        Livewire::test(ListWorkshopOperations::class)
            ->callTableAction('stopTimer', $operation->fresh())
            ->assertNotified('Chrono arrêté');

        $this->assertNotNull($entry->fresh()->ended_at);

        Livewire::test(ListWorkshopOperations::class)
            ->callTableAction('blockOperation', $operation->fresh(), data: [
                'reason' => 'Attente contrôle',
            ])
            ->assertNotified('Opération bloquée');

        $this->assertSame(WorkshopOperationStatus::Blocked, $operation->fresh()->status);
        $this->assertSame('Attente contrôle', $operation->fresh()->blocked_reason);
    }

    public function test_an_operator_can_start_and_complete_an_operation_from_the_list(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::Ready, 'OP-START');

        $this->actingAs($operator);

        Livewire::test(ListWorkshopOperations::class)
            ->callTableAction('startOperation', $operation)
            ->assertNotified('Opération démarrée');

        $this->assertSame(WorkshopOperationStatus::InProgress, $operation->fresh()->status);

        Livewire::test(ListWorkshopOperations::class)
            ->callTableAction('completeOperation', $operation->fresh())
            ->assertNotified('Opération terminée');

        $this->assertSame(WorkshopOperationStatus::Completed, $operation->fresh()->status);
        $this->assertNotNull($operation->fresh()->completed_at);
    }

    public function test_the_detail_page_shows_dependencies_sessions_history_and_live_polling(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-DETAIL');

        $dependency = $this->operation($workOrder, WorkshopOperationStatus::Completed, 'OP-DEPENDENCY');
        $operation->dependencies()->attach($dependency->getKey());

        OperationTimeEntry::factory()
            ->forOperation($operation)
            ->forUser($operator)
            ->create(['entry_type' => OperationTimeEntryType::Manual, 'duration_seconds' => 600]);

        $activeOperator = User::factory()->create(['name' => 'Opérateur Actif']);
        OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($activeOperator)
            ->create(['started_at' => now()->subMinutes(5)]);

        app(WorkOrderOperationAuditService::class)->record(
            $operation,
            $workOrder,
            WorkshopOperationAuditEvent::Started,
            $operator,
            WorkshopOperationStatus::Ready->value,
            WorkshopOperationStatus::InProgress->value,
        );

        $this->actingAs($operator)
            ->get("/workshop/operations/{$operation->getKey()}")
            ->assertOk()
            ->assertSee('OP-DETAIL')
            ->assertSee('OP-DEPENDENCY')
            ->assertSee($operator->name)
            ->assertSee('Opérateur Actif')
            ->assertSee('Session active')
            ->assertSee('Historique')
            ->assertSee('10 min')
            ->assertSee('wire:poll.5s');

        $this->actingAs($operator)
            ->get("/workshop/operations/{$dependency->getKey()}")
            ->assertOk();

        $draftWorkOrder = WorkOrder::factory()->create();
        $draftOperation = $this->operation($draftWorkOrder, WorkshopOperationStatus::Ready, 'OP-HIDDEN');

        $this->actingAs($operator)
            ->get("/workshop/operations/{$draftOperation->getKey()}")
            ->assertNotFound();
    }

    public function test_manual_and_correction_actions_are_visible_only_to_managers(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-MANAGER');

        $entry = OperationTimeEntry::factory()
            ->forOperation($operation)
            ->forUser($operator)
            ->create(['duration_seconds' => 600]);

        $this->actingAs($supervisor)
            ->get("/workshop/operations/{$operation->getKey()}")
            ->assertOk()
            ->assertSee('Saisir un temps manuel')
            ->assertSee('Corriger un temps');

        $this->actingAs($operator)
            ->get("/workshop/operations/{$operation->getKey()}")
            ->assertOk()
            ->assertDontSee('Saisir un temps manuel')
            ->assertDontSee('Corriger un temps');

        $this->assertSame(600, $entry->fresh()->duration_seconds);
    }

    public function test_manager_time_actions_work_from_the_detail_page(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder, WorkshopOperationStatus::InProgress, 'OP-MANUAL');

        $this->actingAs($supervisor);

        Livewire::test(ViewWorkshopOperation::class, ['record' => $operation->getKey()])
            ->callAction('recordManualTime', data: [
                'user_id' => $operator->getKey(),
                'duration_seconds' => 600,
                'notes' => 'Saisie atelier',
            ])
            ->assertNotified('Temps manuel enregistré');

        $entry = OperationTimeEntry::query()->firstOrFail();

        $this->assertSame(600, $entry->duration_seconds);
        $this->assertSame($operator->getKey(), $entry->user_id);

        Livewire::test(ViewWorkshopOperation::class, ['record' => $operation->getKey()])
            ->callAction('correctTime', data: [
                'time_entry_id' => $entry->getKey(),
                'corrected_duration_seconds' => 300,
                'correction_reason' => 'Correction atelier',
            ])
            ->assertNotified('Temps corrigé');

        $this->assertDatabaseHas('operation_time_entries', [
            'original_entry_id' => $entry->getKey(),
            'duration_seconds' => 300,
            'entry_type' => OperationTimeEntryType::Adjustment->value,
        ]);
    }

    protected function operation(
        WorkOrder $workOrder,
        WorkshopOperationStatus $status,
        string $code,
        int $estimatedMinutes = 60,
    ): WorkOrderOperation {
        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'code' => $code,
                'name' => "Opération {$code}",
                'status' => $status,
                'estimated_duration_minutes' => $estimatedMinutes,
                'started_at' => $status === WorkshopOperationStatus::InProgress ? Carbon::now() : null,
            ]);
    }
}
