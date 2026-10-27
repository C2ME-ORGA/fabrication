<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FilamentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_users_without_admin_flag_cannot_access_the_panel(): void
    {
        $user = User::factory()->create();

        $job = Job::factory()->create();
        $workOrder = WorkOrder::factory()->create(['job_id' => $job->id]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/jobs')->assertForbidden();
        $this->actingAs($user)->get('/admin/jobs/create')->assertForbidden();
        $this->actingAs($user)->get("/admin/jobs/{$job->id}")->assertForbidden();
        $this->actingAs($user)->get("/admin/jobs/{$job->id}/edit")->assertForbidden();
        $this->actingAs($user)->get('/admin/work-orders')->assertForbidden();
        $this->actingAs($user)->get('/admin/work-orders/create')->assertForbidden();
        $this->actingAs($user)->get("/admin/work-orders/{$workOrder->id}")->assertForbidden();
        $this->actingAs($user)->get("/admin/work-orders/{$workOrder->id}/edit")->assertForbidden();
    }

    public function test_admins_can_access_the_panel_and_both_resources(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/jobs')->assertOk();
        $this->actingAs($user)->get('/admin/jobs/create')->assertOk();
        $this->actingAs($user)->get('/admin/work-orders')->assertOk();
        $this->actingAs($user)->get('/admin/work-orders/create')->assertOk();
    }

    public function test_admins_can_view_job_and_work_order_detail_pages(): void
    {
        $user = User::factory()->admin()->create();
        $job = Job::factory()->active()->create(['code' => 'AFF-2026-777']);
        $workOrder = WorkOrder::factory()->create([
            'job_id' => $job->id,
            'number' => 'OF-2026-0777',
        ]);

        $this->actingAs($user)
            ->get("/admin/jobs/{$job->id}")
            ->assertOk()
            ->assertSee('AFF-2026-777');

        $this->actingAs($user)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertSee('OF-2026-0777');

        $this->actingAs($user)
            ->get("/admin/work-orders/{$workOrder->id}/edit")
            ->assertOk()
            ->assertSee('AFF-2026-777');

        $completed = WorkOrder::factory()->completed()->create();

        $this->actingAs($user)
            ->get("/admin/work-orders/{$completed->id}/edit")
            ->assertForbidden();
    }

    public function test_job_listing_is_searchable_and_displays_records(): void
    {
        $user = User::factory()->admin()->create();
        $job = Job::factory()->active()->create([
            'code' => 'AFF-2026-042',
            'client_name' => 'Client Test',
        ]);

        $this->actingAs($user)
            ->get('/admin/jobs')
            ->assertOk()
            ->assertSee('AFF-2026-042')
            ->assertSee('Client Test');
    }

    public function test_a_job_holding_work_orders_cannot_be_deleted_by_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();
        WorkOrder::factory()->create(['job_id' => $job->id]);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $job->fresh()));

        $emptyJob = Job::factory()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $emptyJob));
    }

    public function test_completed_and_cancelled_work_orders_cannot_be_updated_by_policy(): void
    {
        $admin = User::factory()->admin()->create();

        $draft = WorkOrder::factory()->create();
        $completed = WorkOrder::factory()->completed()->create();
        $cancelled = WorkOrder::factory()->cancelled()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('update', $draft));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $completed));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $cancelled));
    }

    public function test_only_draft_work_orders_can_be_deleted_by_policy(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', WorkOrder::factory()->create()));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', WorkOrder::factory()->status(WorkOrderStatus::Ready)->create()));
    }

    public function test_non_admins_are_denied_resource_policies(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Job::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', WorkOrder::class));
    }
}
