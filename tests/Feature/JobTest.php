<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_can_be_created_with_valid_data(): void
    {
        $job = Job::query()->create([
            'code' => 'AFF-2026-001',
            'client_name' => 'Client Exemple',
            'name' => 'Hangar monopente',
            'site_address' => '1 rue du Chantier, 35000 Rennes',
            'status' => JobStatus::Draft,
            'notes' => 'Affaire de démonstration',
        ]);

        $this->assertDatabaseHas('jobs', [
            'code' => 'AFF-2026-001',
            'client_name' => 'Client Exemple',
            'status' => 'draft',
        ]);

        $this->assertSame(JobStatus::Draft, $job->fresh()->status);
    }

    public function test_job_code_must_be_unique(): void
    {
        Job::factory()->create(['code' => 'AFF-2026-001']);

        $this->expectException(QueryException::class);

        Job::factory()->create(['code' => 'AFF-2026-001']);
    }

    public function test_a_job_has_many_work_orders_and_the_inverse_relation_works(): void
    {
        $job = Job::factory()->active()->create();

        $first = WorkOrder::factory()->create(['job_id' => $job->id]);
        $second = WorkOrder::factory()->create(['job_id' => $job->id]);

        $this->assertCount(2, $job->workOrders()->get());
        $this->assertTrue($job->workOrders->contains($first));
        $this->assertTrue($job->workOrders->contains($second));

        $this->assertTrue($first->job->is($job));
        $this->assertSame($job->id, $second->job->id);
    }

    public function test_a_job_holding_work_orders_cannot_be_deleted(): void
    {
        $job = Job::factory()->active()->create();
        WorkOrder::factory()->create(['job_id' => $job->id]);

        $this->expectException(QueryException::class);

        $job->delete();
    }

    public function test_a_job_without_work_orders_can_be_deleted(): void
    {
        $job = Job::factory()->create();

        $job->delete();

        $this->assertDatabaseMissing('jobs', ['id' => $job->id]);
    }

    public function test_work_order_status_and_number_are_not_mass_assignable(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $originalNumber = $workOrder->number;

        $workOrder->update([
            'status' => 'completed',
            'number' => 'OF-2099-9999',
        ]);

        $workOrder->refresh();

        $this->assertSame(WorkOrderStatus::Draft, $workOrder->status);
        $this->assertSame($originalNumber, $workOrder->number);
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->update(['is_admin' => true]);

        $this->assertFalse($user->refresh()->isAdmin());
    }

    public function test_users_are_not_panel_admins_by_default(): void
    {
        $this->assertFalse(User::factory()->create()->isAdmin());
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
    }
}
