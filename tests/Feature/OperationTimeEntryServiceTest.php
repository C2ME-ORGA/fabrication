<?php

namespace Tests\Feature;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimeAggregationService;
use App\Services\OperationTimeEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationTimeEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OperationTimeEntryService $service;

    protected User $admin;

    protected User $supervisor;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OperationTimeEntryService::class);
        $this->admin = User::factory()->admin()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->operator = User::factory()->create();
    }

    public function test_an_administrator_can_record_a_manual_time_entry(): void
    {
        $operation = $this->operation();

        $entry = $this->service->recordManual(
            $operation,
            $this->operator,
            600,
            null,
            'Temps relevé sur le terrain',
            $this->admin,
        );

        $this->assertSame(OperationTimeEntryType::Manual, $entry->entry_type);
        $this->assertSame($this->operator->getKey(), $entry->user_id);
        $this->assertSame($this->admin->getKey(), $entry->created_by);
        $this->assertSame(600, $entry->duration_seconds);
        $this->assertNotNull($entry->started_at);
        $this->assertNotNull($entry->ended_at);
        $this->assertNull($entry->original_entry_id);
        $this->assertSame(600, (int) $entry->started_at->diffInSeconds($entry->ended_at));

        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::ManualTimeRecorded->value,
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_a_workshop_supervisor_can_record_a_manual_time_entry(): void
    {
        $operation = $this->operation();

        $entry = $this->service->recordManual(
            $operation,
            $this->operator,
            300,
            null,
            null,
            $this->supervisor,
        );

        $this->assertSame(300, $entry->duration_seconds);
        $this->assertSame($this->supervisor->getKey(), $entry->created_by);
    }

    public function test_a_standard_operator_cannot_record_a_manual_time_entry(): void
    {
        $operation = $this->operation();

        $this->assertValidationFails(
            fn () => $this->service->recordManual(
                $operation,
                $this->operator,
                600,
                null,
                null,
                $this->operator,
            ),
            'time_entries',
        );

        $this->assertDatabaseCount('operation_time_entries', 0);
    }

    public function test_manual_time_rejects_zero_and_negative_durations(): void
    {
        $operation = $this->operation();

        foreach ([0, -1] as $duration) {
            $this->assertValidationFails(
                fn () => $this->service->recordManual(
                    $operation,
                    $this->operator,
                    $duration,
                    null,
                    null,
                    $this->admin,
                ),
                'duration_seconds',
            );
        }

        $this->assertValidationFails(
            fn () => $this->service->recordManual(
                $operation,
                $this->operator,
                31_536_001,
                null,
                null,
                $this->admin,
            ),
            'duration_seconds',
        );
    }

    public function test_a_manual_time_entry_cannot_be_recorded_on_a_cancelled_operation(): void
    {
        $operation = $this->operation(WorkshopOperationStatus::Cancelled);

        $this->assertValidationFails(
            fn () => $this->service->recordManual(
                $operation,
                $this->operator,
                600,
                null,
                null,
                $this->admin,
            ),
            'time_entries',
        );
    }

    public function test_a_correction_is_append_only_and_preserves_the_original_entry(): void
    {
        $operation = $this->operation();
        $original = $this->service->recordManual(
            $operation,
            $this->operator,
            600,
            null,
            'Saisie initiale',
            $this->admin,
        );

        $correction = $this->service->correct(
            $original,
            420,
            'Retrait d\'une pause non travaillée',
            $this->supervisor,
        );

        $this->assertSame(OperationTimeEntryType::Adjustment, $correction->entry_type);
        $this->assertSame($original->getKey(), $correction->original_entry_id);
        $this->assertSame(420, $correction->duration_seconds);
        $this->assertSame($this->supervisor->getKey(), $correction->corrected_by);
        $this->assertSame('Retrait d\'une pause non travaillée', $correction->correction_reason);

        $this->assertSame(600, $original->fresh()->duration_seconds);
        $this->assertNull($original->fresh()->corrected_by);
        $this->assertDatabaseCount('operation_time_entries', 2);

        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::TimeCorrected->value,
            'user_id' => $this->supervisor->getKey(),
        ]);
    }

    public function test_a_correction_requires_a_reason(): void
    {
        $operation = $this->operation();
        $original = $this->service->recordManual($operation, $this->operator, 600, null, null, $this->admin);

        $this->assertValidationFails(
            fn () => $this->service->correct($original, 300, '   ', $this->admin),
            'correction_reason',
        );

        $this->assertDatabaseCount('operation_time_entries', 1);
    }

    public function test_a_standard_operator_cannot_correct_a_time_entry(): void
    {
        $operation = $this->operation();
        $original = $this->service->recordManual($operation, $this->operator, 600, null, null, $this->admin);

        $this->assertValidationFails(
            fn () => $this->service->correct($original, 300, 'Correction', $this->operator),
            'time_entries',
        );
    }

    public function test_an_active_session_or_an_adjustment_cannot_be_corrected(): void
    {
        $operation = $this->operation();

        $active = OperationTimeEntry::factory()
            ->active()
            ->forOperation($operation)
            ->forUser($this->operator)
            ->create();

        $this->assertValidationFails(
            fn () => $this->service->correct($active, 300, 'Correction', $this->admin),
            'time_entry',
        );

        $original = $this->service->recordManual($operation, $this->operator, 600, null, null, $this->admin);
        $adjustment = $this->service->correct($original, 500, 'Première correction', $this->admin);

        $this->assertValidationFails(
            fn () => $this->service->correct($adjustment, 400, 'Seconde correction', $this->admin),
            'time_entry',
        );
    }

    public function test_a_supervisor_can_correct_a_time_after_work_order_completion(): void
    {
        $operation = $this->operation();
        $original = $this->service->recordManual($operation, $this->operator, 600, null, null, $this->admin);

        $operation->workOrder->forceFill(['status' => WorkOrderStatus::Completed])->save();

        $correction = $this->service->correct(
            $original->fresh(),
            300,
            'Correction après clôture',
            $this->supervisor,
        );

        $this->assertSame(OperationTimeEntryType::Adjustment, $correction->entry_type);
        $this->assertSame($original->getKey(), $correction->original_entry_id);
        $this->assertSame(300, $correction->duration_seconds);
        $this->assertSame($this->supervisor->getKey(), $correction->corrected_by);
        $this->assertSame(600, $original->fresh()->duration_seconds);
        $this->assertSame(
            300,
            app(OperationTimeAggregationService::class)->forOperation($operation->fresh())->realSeconds,
        );
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::TimeCorrected->value,
            'user_id' => $this->supervisor->getKey(),
        ]);
    }

    public function test_an_administrator_can_correct_a_time_after_work_order_cancellation(): void
    {
        $operation = $this->operation();
        $original = $this->service->recordManual($operation, $this->operator, 900, null, null, $this->admin);

        $operation->workOrder->forceFill(['status' => WorkOrderStatus::Cancelled])->save();

        $correction = $this->service->correct(
            $original->fresh(),
            450,
            'Correction après annulation',
            $this->admin,
        );

        $this->assertSame(450, $correction->duration_seconds);
        $this->assertSame(900, $original->fresh()->duration_seconds);
        $this->assertSame(
            450,
            app(OperationTimeAggregationService::class)->forOperation($operation->fresh())->realSeconds,
        );
    }

    public function test_an_operator_cannot_correct_a_time_on_a_closed_work_order(): void
    {
        $operation = $this->operation();
        $original = $this->service->recordManual($operation, $this->operator, 600, null, null, $this->admin);

        $operation->workOrder->forceFill(['status' => WorkOrderStatus::Cancelled])->save();

        $this->assertValidationFails(
            fn () => $this->service->correct($original->fresh(), 300, 'Correction', $this->operator),
            'time_entries',
        );

        $this->assertDatabaseCount('operation_time_entries', 1);
    }

    protected function operation(
        WorkshopOperationStatus $status = WorkshopOperationStatus::InProgress,
    ): WorkOrderOperation {
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create(['status' => $status]);
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
