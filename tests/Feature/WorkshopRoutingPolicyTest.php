<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkshopRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class WorkshopRoutingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_standard_operator_cannot_manage_routings(): void
    {
        $operator = User::factory()->create();
        $routing = WorkshopRouting::factory()->create();

        $this->assertFalse(Gate::forUser($operator)->allows('viewAny', WorkshopRouting::class));
        $this->assertFalse(Gate::forUser($operator)->allows('view', $routing));
        $this->assertFalse(Gate::forUser($operator)->allows('create', WorkshopRouting::class));
        $this->assertFalse(Gate::forUser($operator)->allows('update', $routing));
        $this->assertFalse(Gate::forUser($operator)->allows('delete', $routing));
        $this->assertFalse(Gate::forUser($operator)->allows('activate', $routing));
        $this->assertFalse(Gate::forUser($operator)->allows('manageOperations', $routing));
        $this->assertFalse(Gate::forUser($operator)->allows('manageDependencies', $routing));
    }

    public function test_a_workshop_supervisor_can_manage_routings(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $routing = WorkshopRouting::factory()->inactive()->create();

        $this->assertTrue(Gate::forUser($supervisor)->allows('viewAny', WorkshopRouting::class));
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $routing));
        $this->assertTrue(Gate::forUser($supervisor)->allows('create', WorkshopRouting::class));
        $this->assertTrue(Gate::forUser($supervisor)->allows('update', $routing));
        $this->assertTrue(Gate::forUser($supervisor)->allows('delete', $routing));
        $this->assertTrue(Gate::forUser($supervisor)->allows('activate', $routing));
        $this->assertTrue(Gate::forUser($supervisor)->allows('manageOperations', $routing));
        $this->assertTrue(Gate::forUser($supervisor)->allows('manageDependencies', $routing));
    }

    public function test_an_administrator_can_manage_routings(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', WorkshopRouting::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $routing));
        $this->assertTrue(Gate::forUser($admin)->allows('manageOperations', $routing));
    }

    public function test_a_routing_used_by_a_work_order_cannot_be_deleted_by_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $workOrder->forceFill(['workshop_routing_id' => $routing->getKey()])->save();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $routing));
    }
}
