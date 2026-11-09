<?php

namespace Tests\Feature;

use App\Enums\WorkOrderItemStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderItemModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_item_belongs_to_a_work_order_and_is_listed_by_the_relation(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

        $this->assertTrue($item->workOrder->is($workOrder));
        $this->assertTrue($workOrder->items()->whereKey($item->getKey())->exists());
        $this->assertSame(1, $workOrder->items()->count());
    }

    public function test_the_mark_is_trimmed_whitespace_collapsed_and_uppercased_before_storage(): void
    {
        $item = WorkOrderItem::factory()->create([
            'mark' => '  poteau   01  ',
        ]);

        $this->assertSame('POTEAU 01', $item->mark);
        $this->assertSame('POTEAU 01', $item->getRawOriginal('mark'));

        $accented = WorkOrderItem::factory()->create([
            'mark' => '  repère   é01  ',
        ]);

        $this->assertSame('REPERE E01', $accented->mark);
    }

    public function test_accepted_and_remaining_quantities_are_computed_from_the_stored_quantities(): void
    {
        $item = WorkOrderItem::factory()->create([
            'quantity_planned' => 10,
            'quantity_produced' => 7,
            'quantity_rejected' => 2,
        ]);

        $this->assertSame(5, $item->accepted_quantity);
        $this->assertSame(5, $item->remaining_quantity);
    }

    public function test_remaining_quantity_cannot_be_negative_when_production_exceeds_the_plan(): void
    {
        $item = WorkOrderItem::factory()->create([
            'quantity_planned' => 10,
            'quantity_produced' => 14,
            'quantity_rejected' => 1,
        ]);

        $this->assertSame(13, $item->accepted_quantity);
        $this->assertSame(0, $item->remaining_quantity);
    }

    public function test_the_total_weight_is_computed_from_the_unit_weight_and_planned_quantity(): void
    {
        $item = WorkOrderItem::factory()->create([
            'quantity_planned' => 10,
            'unit_weight_kg' => 22.400,
        ]);

        $this->assertEquals(224.0, (float) $item->total_weight_kg);
    }

    public function test_the_total_weight_is_recomputed_when_the_planned_quantity_or_unit_weight_changes(): void
    {
        $item = WorkOrderItem::factory()->create([
            'quantity_planned' => 10,
            'unit_weight_kg' => 12.500,
        ]);

        $item->quantity_planned = 20;
        $item->save();

        $this->assertEquals(250.0, (float) $item->fresh()->total_weight_kg);

        $item->unit_weight_kg = null;
        $item->save();

        $this->assertNull($item->fresh()->total_weight_kg);
    }

    public function test_the_total_weight_cannot_be_imposed_from_a_mass_assignment_payload(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $item = WorkOrderItem::create([
            'work_order_id' => $workOrder->getKey(),
            'mark' => 'MASSE-01',
            'designation' => 'Pièce test',
            'quantity_planned' => 4,
            'unit_weight_kg' => 5.250,
            'total_weight_kg' => 999999,
        ]);

        $this->assertEquals(21.0, (float) $item->total_weight_kg);
        $this->assertDatabaseHas('work_order_items', [
            'id' => $item->getKey(),
            'total_weight_kg' => 21.000,
        ]);
    }

    public function test_a_mark_is_unique_within_a_work_order(): void
    {
        $workOrder = WorkOrder::factory()->create();
        WorkOrderItem::factory()->forWorkOrder($workOrder)->create(['mark' => 'P-01']);

        $this->expectException(QueryException::class);

        WorkOrderItem::factory()->forWorkOrder($workOrder)->create(['mark' => ' p-01 ']);
    }

    public function test_the_same_mark_may_exist_in_two_different_work_orders(): void
    {
        $first = WorkOrder::factory()->create();
        $second = WorkOrder::factory()->create();

        WorkOrderItem::factory()->forWorkOrder($first)->create(['mark' => 'P-01']);
        WorkOrderItem::factory()->forWorkOrder($second)->create(['mark' => 'P-01']);

        $this->assertSame(1, $first->items()->where('mark', 'P-01')->count());
        $this->assertSame(1, $second->items()->where('mark', 'P-01')->count());
    }

    public function test_the_technical_identity_normalizes_text_and_length_for_comparison(): void
    {
        $item = WorkOrderItem::factory()->make([
            'designation' => '  Poteau   H  ',
            'profile' => ' IPE  200 ',
            'steel_grade' => ' s235jr ',
            'length_mm' => 6000,
        ]);

        $this->assertSame([
            'designation' => 'poteau h',
            'profile' => 'ipe 200',
            'steel_grade' => 's235jr',
            'length_mm' => '6000.00',
        ], $item->technicalIdentity());
    }

    public function test_an_item_reports_whether_production_has_been_recorded(): void
    {
        $this->assertFalse(WorkOrderItem::factory()->make()->hasRecordedProduction());

        $this->assertTrue(WorkOrderItem::factory()->make([
            'quantity_produced' => 1,
        ])->hasRecordedProduction());

        $this->assertTrue(WorkOrderItem::factory()->make([
            'quantity_produced' => 0,
            'quantity_rejected' => 1,
        ])->hasRecordedProduction());
    }

    public function test_the_status_is_cast_to_the_enum(): void
    {
        $item = WorkOrderItem::factory()->create([
            'status' => WorkOrderItemStatus::PartiallyProduced,
        ]);

        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $item->status);
        $this->assertSame('partially_produced', $item->getRawOriginal('status'));
    }
}
