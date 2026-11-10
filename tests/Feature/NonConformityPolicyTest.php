<?php

namespace Tests\Feature;

use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\WorkOrderStatus;
use App\Models\NonConformity;
use App\Models\NonConformityCorrectiveAction;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NonConformityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_declare_and_view_an_active_non_conformity_but_not_manage_it(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'detected_by' => $operator->getKey(),
            'created_by' => $operator->getKey(),
        ]);

        $this->assertTrue($operator->can('create', NonConformity::class));
        $this->assertTrue($operator->can('view', $nonConformity));
        $this->assertFalse($operator->can('update', $nonConformity));
        $this->assertFalse($operator->can('qualify', $nonConformity));
        $this->assertFalse($operator->can('close', $nonConformity));
    }

    public function test_an_operator_can_view_a_non_conformity_of_an_active_work_order_even_when_not_declarer_or_responsible(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'detected_by' => User::factory()->create()->getKey(),
            'created_by' => User::factory()->create()->getKey(),
            'responsible_id' => User::factory()->create()->getKey(),
        ]);

        $this->assertTrue($operator->can('view', $nonConformity));
    }

    public function test_a_supervisor_can_manage_and_close_minor_non_conformities_only(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $minor = NonConformity::factory()->severity(NonConformitySeverity::Minor)
            ->status(NonConformityStatus::PendingVerification)
            ->create();
        $major = NonConformity::factory()->severity(NonConformitySeverity::Major)
            ->status(NonConformityStatus::PendingVerification)
            ->create();

        $this->assertTrue($supervisor->can('update', $minor));
        $this->assertTrue($supervisor->can('close', $minor));
        $this->assertFalse($supervisor->can('close', $major));
        $this->assertFalse($supervisor->can('reopen', $minor));
        $this->assertFalse($supervisor->can('verify', $major));
    }

    public function test_a_quality_manager_can_manage_major_and_critical_non_conformities(): void
    {
        $qualityManager = User::factory()->qualityManager()->create();
        $critical = NonConformity::factory()->severity(NonConformitySeverity::Critical)
            ->status(NonConformityStatus::PendingVerification)
            ->create();

        $this->assertTrue($qualityManager->can('update', $critical));
        $this->assertTrue($qualityManager->can('verify', $critical));
        $this->assertTrue($qualityManager->can('close', $critical));

        $closed = NonConformity::factory()->severity(NonConformitySeverity::Critical)
            ->status(NonConformityStatus::Closed)
            ->create([
                'closed_at' => now(),
                'closed_by' => $qualityManager->getKey(),
            ]);

        $this->assertTrue($qualityManager->can('reopen', $closed));
    }

    public function test_an_administrator_can_manage_non_conformities_without_bypassing_the_lifecycle(): void
    {
        $admin = User::factory()->admin()->create();
        $nonConformity = NonConformity::factory()->create();

        $this->assertTrue($admin->can('create', NonConformity::class));
        $this->assertTrue($admin->can('assign', $nonConformity));
        $this->assertTrue($admin->can('cancel', $nonConformity));

        $action = NonConformityCorrectiveAction::factory()->create([
            'non_conformity_id' => $nonConformity->getKey(),
        ]);

        $this->assertTrue($admin->can('update', $action));
    }

    public function test_a_non_conformity_of_a_frozen_work_order_is_readable_only_by_quality_profiles(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $completed = WorkOrder::factory()->completed()->create();
        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $completed->getKey(),
            'detected_by' => User::factory()->create()->getKey(),
            'created_by' => User::factory()->create()->getKey(),
        ]);

        $this->assertFalse($operator->can('view', $nonConformity));
        $this->assertTrue($supervisor->can('view', $nonConformity));
    }
}
