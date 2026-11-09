<?php

namespace Tests\Feature;

use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderItemStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\ItemsRelationManager;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderItemService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderItemFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_items_relation_manager_lists_items_and_shows_the_summary(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $first = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-01',
            'designation' => 'Poteau principal',
            'quantity_planned' => 10,
            'quantity_produced' => 4,
            'quantity_rejected' => 1,
            'status' => WorkOrderItemStatus::PartiallyProduced,
            'unit_weight_kg' => 12.500,
        ]);

        $second = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-02',
            'designation' => 'Traverse secondaire',
            'quantity_planned' => 5,
            'quantity_produced' => 5,
            'quantity_rejected' => 0,
            'status' => WorkOrderItemStatus::Produced,
            'unit_weight_kg' => 4,
        ]);

        $this->actingAs($admin);

        $this->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertSee($workOrder->number);

        $this->itemsManager($workOrder)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('Récapitulatif de production')
            ->assertSee('Quantité prévue')
            ->assertSee('Fabriqué')
            ->assertSee('Accepté')
            ->assertSee('Rebuté')
            ->assertSee('Restant à fabriquer')
            ->assertSee('Poids total indicatif')
            ->assertSee('P-01')
            ->assertSee('Poteau principal')
            ->assertSee('P-02')
            ->assertSee('Traverse secondaire');
    }

    public function test_the_work_order_page_renders_with_item_audit_events(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $service = app(WorkOrderItemService::class);

        $item = $service->create($workOrder, [
            'mark' => 'P-00',
            'designation' => 'Pièce auditée',
            'quantity_planned' => 2,
            'unit_weight_kg' => 5,
        ], $admin);

        $service->recordProduction($item, 2, 0, $admin);
        $service->close($item, $admin);

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertSee($workOrder->number);
    }

    public function test_the_table_can_be_searched_and_filtered(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $debit = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-01',
            'designation' => 'Poteau principal',
            'status' => WorkOrderItemStatus::Todo,
            'operation_group' => 'Débit',
        ]);

        $assembly = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-02',
            'designation' => 'Traverse secondaire',
            'status' => WorkOrderItemStatus::Produced,
            'operation_group' => 'Assemblage',
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->searchTable('P-01')
            ->assertCanSeeTableRecords([$debit])
            ->assertCanNotSeeTableRecords([$assembly])
            ->searchTable('Traverse secondaire')
            ->assertCanSeeTableRecords([$assembly])
            ->assertCanNotSeeTableRecords([$debit])
            ->searchTable(null)
            ->filterTable('status', WorkOrderItemStatus::Produced->value)
            ->assertCanSeeTableRecords([$assembly])
            ->assertCanNotSeeTableRecords([$debit])
            ->resetTableFilters()
            ->filterTable('operation_group', 'Débit')
            ->assertCanSeeTableRecords([$debit])
            ->assertCanNotSeeTableRecords([$assembly]);
    }

    public function test_an_admin_can_create_an_item_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->assertTableActionExists('createItem')
            ->callTableAction('createItem', data: [
                'mark' => '  p-10  ',
                'designation' => 'Poteau créé',
                'profile' => 'IPE 220',
                'steel_grade' => 'S275JR',
                'length_mm' => 6500,
                'quantity_planned' => 10,
                'unit_weight_kg' => 12.500,
                'operation_group' => 'Débit',
                'notes' => 'Créé depuis Filament',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Pièce ajoutée');

        $item = $workOrder->items()->where('mark', 'P-10')->firstOrFail();

        $this->assertSame(WorkOrderItemStatus::Todo, $item->status);
        $this->assertSame(10, $item->quantity_planned);
        $this->assertEquals(125.0, (float) $item->total_weight_kg);
        $this->assertSame(0, $item->accepted_quantity);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::ItemCreated->value,
        ]);
    }

    public function test_creating_a_compatible_duplicate_mark_merges_and_notifies(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $existing = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-11',
            'quantity_planned' => 5,
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->callTableAction('createItem', data: [
                'mark' => 'P-11',
                'designation' => $existing->designation,
                'profile' => $existing->profile,
                'steel_grade' => $existing->steel_grade,
                'length_mm' => $existing->length_mm,
                'quantity_planned' => 4,
                'unit_weight_kg' => 22.400,
                'operation_group' => 'Débit',
                'notes' => null,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Repère fusionné');

        $this->assertSame(1, $workOrder->items()->count());
        $this->assertSame(9, $workOrder->items()->firstOrFail()->quantity_planned);
    }

    public function test_creating_an_incompatible_duplicate_mark_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $existing = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-12',
            'length_mm' => 6000,
            'quantity_planned' => 5,
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->callTableAction('createItem', data: [
                'mark' => 'P-12',
                'designation' => $existing->designation,
                'profile' => $existing->profile,
                'steel_grade' => $existing->steel_grade,
                'length_mm' => 7000,
                'quantity_planned' => 4,
                'unit_weight_kg' => 22.400,
                'operation_group' => 'Débit',
                'notes' => null,
            ])
            ->assertNotified('Action refusée');

        $this->assertSame(5, $existing->fresh()->quantity_planned);
        $this->assertSame(1, $workOrder->items()->count());
    }

    public function test_editing_a_mark_to_a_compatible_target_merges_and_notifies(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $source = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-13',
            'designation' => 'Pièce fusionnable',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 3,
        ]);
        $target = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-14',
            'designation' => 'Pièce fusionnable',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 5,
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->callTableAction('edit', $source, data: [
                'mark' => 'P-14',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Repère fusionné');

        $this->assertNull(WorkOrderItem::query()->find($source->getKey()));
        $this->assertSame(8, $target->fresh()->quantity_planned);
    }

    public function test_production_and_rejects_can_be_recorded_from_the_table(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-15',
            'quantity_planned' => 10,
            'quantity_produced' => 0,
            'quantity_rejected' => 0,
            'status' => WorkOrderItemStatus::Todo,
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->callTableAction('recordProduction', $item, data: [
                'quantity_produced' => 6,
                'quantity_rejected' => 1,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Production enregistrée');

        $item->refresh();

        $this->assertSame(6, $item->quantity_produced);
        $this->assertSame(1, $item->quantity_rejected);
        $this->assertSame(5, $item->accepted_quantity);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $item->status);

        $this->itemsManager($workOrder)
            ->callTableAction('recordProduction', $item, data: [
                'quantity_produced' => 11,
                'quantity_rejected' => 1,
            ])
            ->assertNotified('Production enregistrée');

        $item->refresh();

        $this->assertSame(10, $item->accepted_quantity);
        $this->assertSame(WorkOrderItemStatus::Produced, $item->status);
    }

    public function test_rejects_greater_than_produced_are_refused_from_the_table(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-16',
            'quantity_planned' => 10,
            'quantity_produced' => 0,
            'quantity_rejected' => 0,
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->callTableAction('recordProduction', $item, data: [
                'quantity_produced' => 2,
                'quantity_rejected' => 3,
            ])
            ->assertNotified('Action refusée');

        $this->assertSame(0, $item->fresh()->quantity_produced);
        $this->assertSame(0, $item->fresh()->quantity_rejected);
    }

    public function test_start_close_and_cancel_actions_are_available_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $toStart = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-17',
            'status' => WorkOrderItemStatus::Todo,
        ]);
        $toClose = WorkOrderItem::factory()->produced()->forWorkOrder($workOrder)->create([
            'mark' => 'P-18',
        ]);
        $toCancel = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-19',
            'status' => WorkOrderItemStatus::Todo,
        ]);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->callTableAction('start', $toStart)
            ->assertNotified('Pièce démarrée')
            ->callTableAction('close', $toClose)
            ->assertNotified('Pièce clôturée')
            ->callTableAction('cancel', $toCancel)
            ->assertNotified('Pièce annulée');

        $this->assertSame(WorkOrderItemStatus::InProgress, $toStart->fresh()->status);
        $this->assertSame(WorkOrderItemStatus::Closed, $toClose->fresh()->status);
        $this->assertSame(WorkOrderItemStatus::Cancelled, $toCancel->fresh()->status);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::ItemStatusChanged->value,
        ]);
    }

    public function test_delete_is_available_without_production_and_hidden_after_production(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $deletable = WorkOrderItem::factory()->forWorkOrder($workOrder)->create(['mark' => 'P-20']);
        $produced = WorkOrderItem::factory()->produced()->forWorkOrder($workOrder)->create(['mark' => 'P-21']);

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->assertTableActionVisible('delete', $deletable)
            ->assertTableActionHidden('delete', $produced)
            ->callTableAction('delete', $deletable)
            ->assertNotified('Pièce supprimée');

        $this->assertNull(WorkOrderItem::query()->find($deletable->getKey()));
        $this->assertNotNull(WorkOrderItem::query()->find($produced->getKey()));
    }

    public function test_locked_work_orders_hide_every_mutating_action(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([WorkOrder::factory()->completed()->create(), WorkOrder::factory()->cancelled()->create()] as $workOrder) {
            $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
                'status' => WorkOrderItemStatus::Todo,
            ]);

            $this->actingAs($admin);

            $this->itemsManager($workOrder)
                ->assertCanSeeTableRecords([$item])
                ->assertTableActionHidden('createItem')
                ->assertTableActionHidden('edit', $item)
                ->assertTableActionHidden('recordProduction', $item)
                ->assertTableActionHidden('start', $item)
                ->assertTableActionHidden('close', $item)
                ->assertTableActionHidden('cancel', $item)
                ->assertTableActionHidden('delete', $item);
        }
    }

    public function test_a_non_admin_cannot_access_the_items_relation_manager(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($user);

        $this->itemsManager($workOrder)->assertForbidden();
    }

    public function test_a_direct_locked_action_call_is_refused_by_the_server_guard(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->completed()->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

        $this->actingAs($admin);

        $component = $this->itemsManager($workOrder);

        $this->expectException(AuthorizationException::class);

        $component->instance()->recordProductionForItem($item, 1, 0);
    }

    protected function itemsManager(WorkOrder $workOrder): Testable
    {
        return Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ]);
    }
}
