<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class WorkOrderItemPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_manage_items_on_an_editable_work_order(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', WorkOrderItem::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $item));
        $this->assertTrue(Gate::forUser($admin)->allows('create', [WorkOrderItem::class, $workOrder]));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $item));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $item));
        $this->assertTrue(Gate::forUser($admin)->allows('recordProduction', $item));
        $this->assertTrue(Gate::forUser($admin)->allows('changeStatus', $item));
        $this->assertTrue(Gate::forUser($admin)->allows('manageItems', $workOrder));
    }

    public function test_a_non_admin_cannot_manage_items(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', WorkOrderItem::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $item));
        $this->assertFalse(Gate::forUser($user)->allows('create', [WorkOrderItem::class, $workOrder]));
        $this->assertFalse(Gate::forUser($user)->allows('update', $item));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $item));
        $this->assertFalse(Gate::forUser($user)->allows('recordProduction', $item));
        $this->assertFalse(Gate::forUser($user)->allows('changeStatus', $item));
        $this->assertFalse(Gate::forUser($user)->allows('manageItems', $workOrder));
    }

    public function test_a_locked_work_order_blocks_item_mutations_but_not_viewing(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([WorkOrder::factory()->completed()->create(), WorkOrder::factory()->cancelled()->create()] as $workOrder) {
            $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

            $this->assertTrue(Gate::forUser($admin)->allows('view', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('create', [WorkOrderItem::class, $workOrder]));
            $this->assertFalse(Gate::forUser($admin)->allows('update', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('delete', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('recordProduction', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('changeStatus', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('manageItems', $workOrder));
        }
    }

    public function test_a_produced_item_can_no_longer_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $item = WorkOrderItem::factory()->produced()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('update', $item));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $item));
    }

    public function test_a_work_order_with_import_history_cannot_be_deleted_by_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $workOrder));

        WorkOrderBomImport::factory()->forWorkOrder($workOrder)->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $workOrder->fresh()));
    }

    public function test_a_work_order_with_produced_items_cannot_be_deleted_by_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $workOrder));

        WorkOrderItem::factory()->produced()->forWorkOrder($workOrder)->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $workOrder->fresh()));
    }

    public function test_a_closed_or_cancelled_item_is_frozen(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            WorkOrderItem::factory()->closed()->create(),
            WorkOrderItem::factory()->cancelled()->create(),
        ] as $item) {
            $this->assertFalse(Gate::forUser($admin)->allows('update', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('delete', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('recordProduction', $item));
            $this->assertFalse(Gate::forUser($admin)->allows('changeStatus', $item));
        }
    }
}
