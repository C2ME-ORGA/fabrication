<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderService::class);
    }

    public function test_a_work_order_is_created_with_a_generated_number(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'Fabrication des poteaux',
            'priority' => WorkOrderPriority::High->value,
            'planned_start_at' => '2026-11-02 08:00:00',
            'planned_end_at' => '2026-11-10 17:00:00',
        ], $user);

        $this->assertMatchesRegularExpression('/^OF-\d{4}-0001$/', $workOrder->number);
        $this->assertSame(WorkOrderStatus::Draft, $workOrder->status);
        $this->assertSame(WorkOrderPriority::High, $workOrder->priority);
        $this->assertSame($job->id, $workOrder->job_id);
        $this->assertSame($user->id, $workOrder->created_by);
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'number' => $workOrder->number,
            'status' => 'draft',
        ]);
    }

    public function test_several_work_orders_can_belong_to_the_same_job_with_distinct_numbers(): void
    {
        $job = Job::factory()->active()->create();

        $numbers = collect(['Poteaux', 'Traverses', 'Éléments secondaires'])
            ->map(fn (string $title): string => $this->service->create([
                'job_id' => $job->id,
                'title' => "Fabrication des {$title}",
            ])->number);

        $this->assertCount(3, $numbers->unique());
        $this->assertSame(3, $job->workOrders()->count());
        $this->assertSame(
            [$this->expectedNumber(1), $this->expectedNumber(2), $this->expectedNumber(3)],
            $numbers->all(),
        );
    }

    public function test_factory_numbers_do_not_collide_with_generated_numbers(): void
    {
        $job = Job::factory()->active()->create();

        $factoryWorkOrder = WorkOrder::factory()->create(['job_id' => $job->id]);
        $generatedWorkOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'Numéro généré',
        ]);

        $this->assertNotSame($factoryWorkOrder->number, $generatedWorkOrder->number);
        $this->assertSame($this->expectedNumber(1), $generatedWorkOrder->number);
    }

    public function test_a_work_order_cannot_be_created_without_a_job(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'title' => 'Sans affaire',
        ]);
    }

    public function test_a_work_order_cannot_be_created_for_a_closed_job(): void
    {
        $job = Job::factory()->closed()->create();

        $this->expectException(ValidationException::class);

        $this->service->create([
            'job_id' => $job->id,
            'title' => 'Interdit',
        ]);
    }

    public function test_a_work_order_cannot_be_created_for_a_suspended_job(): void
    {
        $job = Job::factory()->onHold()->create();

        $this->expectException(ValidationException::class);

        $this->service->create([
            'job_id' => $job->id,
            'title' => 'Interdit',
        ]);
    }

    public function test_the_number_cannot_be_supplied_by_the_caller(): void
    {
        $job = Job::factory()->active()->create();

        $this->expectException(ValidationException::class);

        $this->service->create([
            'job_id' => $job->id,
            'number' => 'OF-1999-9999',
            'title' => 'Numéro libre',
        ]);
    }

    public function test_planned_end_cannot_precede_planned_start(): void
    {
        $job = Job::factory()->active()->create();

        $this->expectException(ValidationException::class);

        $this->service->create([
            'job_id' => $job->id,
            'title' => 'Dates incohérentes',
            'planned_start_at' => '2026-11-10',
            'planned_end_at' => '2026-11-01',
        ]);
    }

    public function test_update_rejects_a_null_priority(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->update($workOrder, ['priority' => null]);
    }

    public function test_creation_without_priority_uses_the_normal_default(): void
    {
        $job = Job::factory()->active()->create();

        $workOrder = $this->service->create([
            'job_id' => $job->id,
            'title' => 'Priorité par défaut',
            'priority' => null,
        ]);

        $this->assertSame(WorkOrderPriority::Normal, $workOrder->priority);
    }

    public function test_update_rejects_incoherent_planned_dates(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'planned_start_at' => '2026-11-10',
            'planned_end_at' => '2026-11-20',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->update($workOrder, [
            'planned_end_at' => '2026-11-01',
        ]);
    }

    public function test_the_job_of_a_work_order_cannot_be_changed(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $otherJob = Job::factory()->active()->create();

        $this->expectException(ValidationException::class);

        $this->service->update($workOrder, ['job_id' => $otherJob->id]);
    }

    public function test_valid_status_transitions_follow_the_matrix(): void
    {
        $job = Job::factory()->active()->create();
        $user = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready, $user);
        $this->assertSame(WorkOrderStatus::Ready, $workOrder->status);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Released, $user);
        $this->assertSame(WorkOrderStatus::Released, $workOrder->status);
        $this->assertNotNull($workOrder->released_at);
        $this->assertSame($user->id, $workOrder->released_by);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::InProgress, $user);
        $this->assertSame(WorkOrderStatus::InProgress, $workOrder->status);
        $this->assertNotNull($workOrder->actual_start_at);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::QualityCheck, $user);
        $this->assertSame(WorkOrderStatus::QualityCheck, $workOrder->status);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Completed, $user);
        $this->assertSame(WorkOrderStatus::Completed, $workOrder->status);
        $this->assertNotNull($workOrder->actual_end_at);
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->transitionTo($workOrder, WorkOrderStatus::Completed);
    }

    public function test_a_work_order_can_only_be_released_when_its_job_is_active(): void
    {
        $job = Job::factory()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Ready);

        $this->expectException(ValidationException::class);

        $this->service->transitionTo($workOrder, WorkOrderStatus::Released);
    }

    public function test_a_completed_work_order_cannot_transition_or_be_updated(): void
    {
        $workOrder = WorkOrder::factory()->completed()->create();

        try {
            $this->service->transitionTo($workOrder, WorkOrderStatus::InProgress);
            $this->fail('Une transition depuis un OF terminé aurait dû être refusée.');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        $this->service->update($workOrder, ['title' => 'Modification interdite']);
    }

    public function test_a_cancelled_work_order_is_frozen(): void
    {
        $workOrder = WorkOrder::factory()->cancelled()->create();

        try {
            $this->service->transitionTo($workOrder, WorkOrderStatus::Ready);
            $this->fail('Une transition depuis un OF annulé aurait dû être refusée.');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        $this->service->update($workOrder, ['title' => 'Modification interdite']);
    }

    public function test_cancellation_keeps_the_record_and_its_number(): void
    {
        $job = Job::factory()->active()->create();

        $workOrder = $this->service->create(['job_id' => $job->id, 'title' => 'À annuler']);
        $number = $workOrder->number;

        $workOrder = $this->service->transitionTo($workOrder, WorkOrderStatus::Cancelled);

        $this->assertSame(WorkOrderStatus::Cancelled, $workOrder->status);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'number' => $number]);
    }

    public function test_only_draft_work_orders_can_be_deleted(): void
    {
        $draft = WorkOrder::factory()->create();
        $ready = WorkOrder::factory()->status(WorkOrderStatus::Ready)->create();

        $this->service->delete($draft);
        $this->assertDatabaseMissing('work_orders', ['id' => $draft->id]);

        $this->expectException(ValidationException::class);
        $this->service->delete($ready);
    }

    public function test_a_job_can_still_be_updated_after_being_closed_but_accepts_no_new_work_order(): void
    {
        $job = Job::factory()->active()->create();
        $job->update(['status' => JobStatus::Closed]);

        $this->assertFalse($job->fresh()->acceptsNewWorkOrders());
    }

    protected function expectedNumber(int $sequence): string
    {
        return sprintf('OF-%d-%04d', now()->year, $sequence);
    }
}
