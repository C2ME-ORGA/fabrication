<?php

namespace Tests\Feature;

use App\Enums\OperationTimeEntryType;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrderOperation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationTimeEntryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_have_two_active_sessions_across_work_orders(): void
    {
        $user = User::factory()->create();
        $firstOperation = WorkOrderOperation::factory()->create();
        $secondOperation = WorkOrderOperation::factory()->create();

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($firstOperation)
            ->forUser($user)
            ->create();

        $this->expectException(QueryException::class);

        OperationTimeEntry::factory()
            ->active()
            ->forOperation($secondOperation)
            ->forUser($user)
            ->create();
    }

    public function test_several_operators_can_track_time_on_the_same_operation(): void
    {
        $operation = WorkOrderOperation::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        OperationTimeEntry::factory()->active()->forOperation($operation)->forUser($first)->create();
        OperationTimeEntry::factory()->active()->forOperation($operation)->forUser($second)->create();

        $this->assertSame(2, $operation->activeTimeEntries()->count());
    }

    public function test_closing_a_session_frees_the_active_slot_for_the_same_operator(): void
    {
        $user = User::factory()->create();
        $firstOperation = WorkOrderOperation::factory()->create();
        $secondOperation = WorkOrderOperation::factory()->create();

        $first = OperationTimeEntry::factory()
            ->active()
            ->forOperation($firstOperation)
            ->forUser($user)
            ->create();

        $first->update([
            'ended_at' => now(),
            'duration_seconds' => 120,
        ]);

        $second = OperationTimeEntry::factory()
            ->active()
            ->forOperation($secondOperation)
            ->forUser($user)
            ->create();

        $this->assertFalse($first->fresh()->isActive());
        $this->assertTrue($second->fresh()->isActive());
    }

    public function test_real_duration_sums_closed_sessions_only(): void
    {
        $operation = WorkOrderOperation::factory()->create();

        OperationTimeEntry::factory()->forOperation($operation)->create(['duration_seconds' => 120]);
        OperationTimeEntry::factory()->forOperation($operation)->create(['duration_seconds' => 180]);
        OperationTimeEntry::factory()->active()->forOperation($operation)->create();

        $this->assertSame(300, $operation->recordedDurationSeconds());
        $this->assertSame(1, $operation->activeTimeEntries()->count());
    }

    public function test_time_entry_type_is_cast_and_dates_are_stored(): void
    {
        $entry = OperationTimeEntry::factory()->manual()->create([
            'started_at' => '2026-01-15 08:30:00',
            'ended_at' => '2026-01-15 09:00:00',
            'duration_seconds' => 1800,
        ]);

        $entry->refresh();

        $this->assertSame(OperationTimeEntryType::Manual, $entry->entry_type);
        $this->assertSame(1800, $entry->duration_seconds);
        $this->assertSame('2026-01-15 08:30:00', $entry->started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-15 09:00:00', $entry->ended_at?->format('Y-m-d H:i:s'));
    }
}
