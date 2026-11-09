<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Livewire\Workshop\ActiveTimers;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveTimersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_operator_sees_only_his_own_active_timer_and_the_duration_is_computed_server_side(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');

        $first = User::factory()->create(['name' => 'Opérateur Un']);
        $second = User::factory()->create(['name' => 'Opérateur Deux']);
        $operation = $this->operation();

        $firstEntry = OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($first)
            ->create(['started_at' => Carbon::now()->subMinutes(90)]);

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($second)
            ->create(['started_at' => Carbon::now()->subHour()]);

        $this->actingAs($first);

        Livewire::test(ActiveTimers::class)
            ->assertSee('Opérateur Un')
            ->assertDontSee('Opérateur Deux')
            ->assertSee('1 h 30 min')
            ->assertSee('wire:poll.5s')
            ->call('$refresh');

        $this->assertNull($firstEntry->fresh()->ended_at);
        $this->assertNull($firstEntry->fresh()->duration_seconds);
    }

    public function test_a_manager_sees_all_active_timers(): void
    {
        $first = User::factory()->create(['name' => 'Opérateur Un']);
        $second = User::factory()->create(['name' => 'Opérateur Deux']);
        $supervisor = User::factory()->workshopSupervisor()->create();
        $operation = $this->operation();

        OperationTimeEntry::factory()->active()->forOperation($operation)->forUser($first)->create();
        OperationTimeEntry::factory()->active()->forOperation($operation)->forUser($second)->create();

        $this->actingAs($supervisor);

        Livewire::test(ActiveTimers::class)
            ->assertSee('Opérateur Un')
            ->assertSee('Opérateur Deux');
    }

    public function test_a_closed_session_disappears_on_refresh_without_being_modified_by_the_view(): void
    {
        $operator = User::factory()->create(['name' => 'Opérateur Un']);
        $operation = $this->operation();

        $entry = OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($operator)
            ->create();

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTimers::class)
            ->assertSee($operation->code)
            ->assertSee('Opérateur Un');

        $entry->forceFill([
            'ended_at' => now(),
            'duration_seconds' => 120,
        ])->save();

        $component
            ->call('$refresh')
            ->assertSee('Aucun chronomètre actif')
            ->assertDontSee($operation->code);
    }

    protected function operation(): WorkOrderOperation
    {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        return WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'code' => 'ACTIVE-OP',
            'status' => WorkshopOperationStatus::InProgress,
        ]);
    }
}
