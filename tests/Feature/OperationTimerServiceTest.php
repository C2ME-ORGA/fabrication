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
use App\Services\OperationTimerService;
use App\Services\WorkOrderOperationService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationTimerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OperationTimerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OperationTimerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_operator_can_start_and_stop_a_timer_with_a_server_computed_duration(): void
    {
        $operator = User::factory()->create();
        $operation = $this->operation();

        Carbon::setTestNow('2026-11-09 10:00:00');

        $started = $this->service->start($operation, $operator);

        $this->assertSame(OperationTimeEntryType::Timer, $started->entry_type);
        $this->assertSame($operator->getKey(), $started->user_id);
        $this->assertTrue($started->isActive());
        $this->assertNull($started->duration_seconds);
        $this->assertNotNull($operation->fresh()->started_at);

        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::TimerStarted->value,
            'user_id' => $operator->getKey(),
        ]);

        Carbon::setTestNow('2026-11-09 10:01:30');

        $stopped = $this->service->stop($operation->fresh(), $operator);

        $this->assertFalse($stopped->isActive());
        $this->assertSame(90, $stopped->duration_seconds);
        $this->assertDatabaseHas('work_order_operation_audit_logs', [
            'work_order_operation_id' => $operation->getKey(),
            'event' => WorkshopOperationAuditEvent::TimerStopped->value,
            'user_id' => $operator->getKey(),
        ]);
    }

    public function test_a_closed_session_cannot_be_stopped_twice(): void
    {
        $operator = User::factory()->create();
        $operation = $this->operation();

        $this->service->start($operation, $operator);
        $this->service->stop($operation, $operator);

        $this->assertValidationFails(
            fn () => $this->service->stop($operation->fresh(), $operator),
            'time_entries',
        );
    }

    public function test_an_operator_cannot_stop_another_operators_session(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $operation = $this->operation();

        $this->service->start($operation, $first);

        $this->assertValidationFails(
            fn () => $this->service->stop($operation->fresh(), $second),
            'time_entries',
        );

        $this->assertNull(
            OperationTimeEntry::query()
                ->where('work_order_operation_id', $operation->getKey())
                ->firstOrFail()
                ->ended_at,
        );
    }

    public function test_an_operator_cannot_have_two_active_sessions_across_work_orders(): void
    {
        $operator = User::factory()->create();
        $first = $this->operation();
        $second = $this->operation();

        $this->service->start($first, $operator);

        $this->assertValidationFails(
            fn () => $this->service->start($second, $operator),
            'time_entries',
        );

        $this->assertSame(1, OperationTimeEntry::query()->whereNull('ended_at')->count());
    }

    public function test_two_operators_can_track_the_same_operation_simultaneously(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $operation = $this->operation();

        $this->service->start($operation, $first);
        $this->service->start($operation, $second);

        $this->assertSame(2, OperationTimeEntry::query()
            ->where('work_order_operation_id', $operation->getKey())
            ->whereNull('ended_at')
            ->count());
    }

    public function test_a_timer_cannot_start_on_a_non_running_operation_or_a_non_released_work_order(): void
    {
        $operator = User::factory()->create();

        $ready = $this->operation(WorkshopOperationStatus::Ready);
        $this->assertValidationFails(fn () => $this->service->start($ready, $operator), 'status');

        $draftWorkOrder = WorkOrder::factory()->create();
        $draftOperation = $this->operation(WorkshopOperationStatus::InProgress, $draftWorkOrder);
        $this->assertValidationFails(fn () => $this->service->start($draftOperation, $operator), 'status');
    }

    public function test_a_work_order_cannot_be_cancelled_while_a_timer_is_active(): void
    {
        $operator = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $operation = $this->operation();
        $workOrder = $operation->workOrder;

        $this->service->start($operation, $operator);

        $this->assertValidationFails(
            fn () => app(WorkOrderService::class)->transitionTo($workOrder, WorkOrderStatus::Cancelled, $admin),
            'time_entries',
        );

        $this->assertSame(WorkOrderStatus::Released, $workOrder->fresh()->status);
        $this->assertSame(1, OperationTimeEntry::query()->whereNull('ended_at')->count());

        $this->service->stop($operation->fresh(), $operator);

        $this->assertValidationFails(
            fn () => app(WorkOrderService::class)->transitionTo($workOrder->fresh(), WorkOrderStatus::Cancelled, $admin),
            'operations',
        );

        app(WorkOrderOperationService::class)->cancel(
            $operation->fresh(),
            $admin,
            'Opération annulée avant la clôture de l\'OF',
        );

        $cancelled = app(WorkOrderService::class)->transitionTo(
            $workOrder->fresh(),
            WorkOrderStatus::Cancelled,
            $admin,
            'Annulation après arrêt des chronos',
        );

        $this->assertSame(WorkOrderStatus::Cancelled, $cancelled->status);
    }

    protected function operation(
        WorkshopOperationStatus $status = WorkshopOperationStatus::InProgress,
        ?WorkOrder $workOrder = null,
    ): WorkOrderOperation {
        $workOrder ??= WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        return WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create([
                'status' => $status,
                'started_at' => $status === WorkshopOperationStatus::InProgress ? now() : null,
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
