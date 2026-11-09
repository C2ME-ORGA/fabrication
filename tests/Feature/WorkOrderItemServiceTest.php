<?php

namespace Tests\Feature;

use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderItemStatus;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderItemService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderItemServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderItemService $service;

    protected WorkOrderService $workOrders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderItemService::class);
        $this->workOrders = app(WorkOrderService::class);
    }

    public function test_an_item_is_created_with_normalized_mark_computed_values_and_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $item = $this->service->create($workOrder, [
            'mark' => '  poteau   01 ',
            'designation' => 'Poteau principal',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 10,
            'unit_weight_kg' => 12.500,
            'operation_group' => 'Débit',
            'notes' => 'Pièce de rive',
        ], $admin);

        $this->assertSame('POTEAU 01', $item->mark);
        $this->assertSame(WorkOrderItemStatus::Todo, $item->status);
        $this->assertSame(0, $item->accepted_quantity);
        $this->assertSame(10, $item->remaining_quantity);
        $this->assertEquals(125.0, (float) $item->total_weight_kg);

        $this->assertDatabaseHas('work_order_items', [
            'id' => $item->getKey(),
            'work_order_id' => $workOrder->getKey(),
            'mark' => 'POTEAU 01',
            'quantity_planned' => 10,
        ]);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'user_id' => $admin->getKey(),
            'event' => WorkOrderAuditEvent::ItemCreated->value,
        ]);
    }

    public function test_an_item_can_be_updated_and_the_total_weight_is_recomputed(): void
    {
        $item = WorkOrderItem::factory()->create([
            'quantity_planned' => 5,
            'unit_weight_kg' => 10,
        ]);

        $updated = $this->service->update($item, [
            'designation' => 'Poteau modifié',
            'quantity_planned' => 20,
            'unit_weight_kg' => 11.250,
        ]);

        $this->assertSame('Poteau modifié', $updated->designation);
        $this->assertSame(20, $updated->quantity_planned);
        $this->assertEquals(225.0, (float) $updated->total_weight_kg);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $item->work_order_id,
            'event' => WorkOrderAuditEvent::ItemUpdated->value,
        ]);
    }

    public function test_protected_fields_are_rejected_from_create_and_update_payloads(): void
    {
        $workOrder = WorkOrder::factory()->create();

        try {
            $this->service->create($workOrder, [
                'mark' => 'P-01',
                'designation' => 'Pièce test',
                'quantity_planned' => 1,
                'status' => WorkOrderItemStatus::Produced->value,
            ]);

            $this->fail('La création aurait dû refuser le champ protégé status.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('status', (string) collect($exception->errors())->flatten()->first());
        }

        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

        $this->expectException(ValidationException::class);

        $this->service->update($item, [
            'quantity_produced' => 5,
        ]);
    }

    public function test_a_compatible_duplicate_mark_is_merged_and_only_the_planned_quantity_is_added(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $first = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-01',
            'quantity_planned' => 5,
        ]));

        $first = $this->service->recordProduction($first, 3, 1);

        $merged = $this->service->create($workOrder, $this->itemPayload([
            'mark' => ' p-01 ',
            'quantity_planned' => 4,
        ]));

        $this->assertTrue($merged->is($first));
        $this->assertSame(9, $merged->quantity_planned);
        $this->assertSame(3, $merged->quantity_produced);
        $this->assertSame(1, $merged->quantity_rejected);
        $this->assertSame(2, $merged->accepted_quantity);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $merged->status);
        $this->assertSame(1, $workOrder->items()->count());

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::ItemMerged->value,
        ]);
    }

    public function test_merging_into_a_produced_item_recomputes_its_status_from_the_accepted_quantity(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $first = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-02',
            'quantity_planned' => 5,
        ]));
        $first = $this->service->recordProduction($first, 5, 0);

        $this->assertSame(WorkOrderItemStatus::Produced, $first->status);

        $merged = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-02',
            'quantity_planned' => 5,
        ]));

        $this->assertSame(10, $merged->quantity_planned);
        $this->assertSame(5, $merged->accepted_quantity);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $merged->status);
    }

    public function test_an_incompatible_duplicate_mark_is_refused_without_any_write(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $existing = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-03',
            'quantity_planned' => 5,
            'length_mm' => 6000,
        ]));

        try {
            $this->service->create($workOrder, $this->itemPayload([
                'mark' => 'P-03',
                'quantity_planned' => 4,
                'length_mm' => 7000,
            ]));

            $this->fail('La fusion incompatible aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'caractéristiques techniques différentes',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(5, $existing->fresh()->quantity_planned);
        $this->assertSame(1, $workOrder->items()->count());
        $this->assertSame(
            0,
            $workOrder->auditLogs()->where('event', WorkOrderAuditEvent::ItemMerged->value)->count(),
        );
    }

    public function test_update_can_merge_into_another_compatible_mark_when_the_source_has_no_production(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $source = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-04',
            'quantity_planned' => 3,
        ]));
        $target = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-05',
            'quantity_planned' => 5,
        ]));

        $merged = $this->service->update($source, ['mark' => 'P-05']);

        $this->assertTrue($merged->is($target));
        $this->assertSame(8, $merged->quantity_planned);
        $this->assertNull(WorkOrderItem::query()->find($source->getKey()));
        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::ItemMerged->value,
        ]);
    }

    public function test_update_merge_is_refused_when_the_source_has_recorded_production(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $source = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-06',
            'quantity_planned' => 3,
        ]));
        $source = $this->service->recordProduction($source, 1, 0);

        $target = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-07',
            'quantity_planned' => 5,
        ]));

        try {
            $this->service->update($source, ['mark' => 'P-07']);

            $this->fail('La fusion d’une pièce produite aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'production ou des rebuts',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(3, $source->fresh()->quantity_planned);
        $this->assertSame(5, $target->fresh()->quantity_planned);
        $this->assertSame(2, $workOrder->items()->count());
    }

    public function test_update_merge_is_refused_when_the_characteristics_differ(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $source = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-08',
            'quantity_planned' => 3,
            'length_mm' => 6000,
        ]));
        $target = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-09',
            'quantity_planned' => 5,
            'length_mm' => 7000,
        ]));

        try {
            $this->service->update($source, ['mark' => 'P-09']);

            $this->fail('La fusion incompatible aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'caractéristiques techniques différentes',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame('P-08', $source->fresh()->mark);
        $this->assertSame(5, $target->fresh()->quantity_planned);
        $this->assertSame(2, $workOrder->items()->count());
    }

    public function test_production_and_rejects_are_validated_and_drive_the_status(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $item = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-10',
            'quantity_planned' => 10,
        ]));

        $item = $this->service->recordProduction($item, 6, 1);
        $this->assertSame(5, $item->accepted_quantity);
        $this->assertSame(5, $item->remaining_quantity);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $item->status);

        $item = $this->service->recordProduction($item, 10, 1);
        $this->assertSame(9, $item->accepted_quantity);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $item->status);

        $item = $this->service->recordProduction($item, 11, 1);
        $this->assertSame(10, $item->accepted_quantity);
        $this->assertSame(0, $item->remaining_quantity);
        $this->assertSame(WorkOrderItemStatus::Produced, $item->status);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::ItemProductionRecorded->value,
        ]);
    }

    public function test_rejects_greater_than_produced_are_refused_without_writing(): void
    {
        $item = WorkOrderItem::factory()->create([
            'quantity_planned' => 10,
            'quantity_produced' => 0,
            'quantity_rejected' => 0,
        ]);

        try {
            $this->service->recordProduction($item, 2, 3);

            $this->fail('Les rebuts supérieurs à la production auraient dû être refusés.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'ne peut pas dépasser',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(0, $item->fresh()->quantity_produced);
        $this->assertSame(0, $item->fresh()->quantity_rejected);
    }

    public function test_negative_quantities_are_refused(): void
    {
        $workOrder = WorkOrder::factory()->create();

        try {
            $this->service->create($workOrder, $this->itemPayload([
                'mark' => 'P-11',
                'quantity_planned' => -1,
            ]));

            $this->fail('Une quantité prévue négative aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity_planned', $exception->errors());
        }

        $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

        $this->expectException(ValidationException::class);

        $this->service->recordProduction($item, -1, 0);
    }

    public function test_status_transitions_are_controlled_and_audited(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $item = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-12',
            'quantity_planned' => 2,
        ]));

        $item = $this->service->start($item);
        $this->assertSame(WorkOrderItemStatus::InProgress, $item->status);

        try {
            $this->service->close($item);
            $this->fail('Une pièce non fabriquée ne peut pas être clôturée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $item = $this->service->recordProduction($item, 2, 0);
        $this->assertSame(WorkOrderItemStatus::Produced, $item->status);

        $item = $this->service->close($item);
        $this->assertSame(WorkOrderItemStatus::Closed, $item->status);

        $this->expectException(ValidationException::class);

        $this->service->cancel($item);
    }

    public function test_a_partially_produced_item_cannot_be_started_again(): void
    {
        $item = WorkOrderItem::factory()->partiallyProduced()->create();

        $this->expectException(ValidationException::class);

        $this->service->start($item);
    }

    public function test_planned_quantity_changes_keep_the_status_coherent(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $item = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'P-13',
            'quantity_planned' => 5,
        ]));
        $item = $this->service->recordProduction($item, 5, 0);
        $this->assertSame(WorkOrderItemStatus::Produced, $item->status);

        $item = $this->service->update($item, ['quantity_planned' => 10]);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $item->status);

        $item = $this->service->update($item, ['quantity_planned' => 5]);
        $this->assertSame(WorkOrderItemStatus::Produced, $item->status);
    }

    public function test_locked_work_orders_refuse_every_item_mutation(): void
    {
        foreach ([WorkOrderStatus::Completed, WorkOrderStatus::Cancelled] as $status) {
            $workOrder = WorkOrder::factory()->status($status)->create();
            $item = WorkOrderItem::factory()->forWorkOrder($workOrder)->create();

            $operations = [
                'create' => fn () => $this->service->create($workOrder, $this->itemPayload(['mark' => 'LOCK-'.$status->value])),
                'update' => fn () => $this->service->update($item, ['designation' => 'Interdit']),
                'delete' => fn () => $this->service->delete($item),
                'production' => fn () => $this->service->recordProduction($item, 1, 0),
                'start' => fn () => $this->service->start($item),
                'close' => fn () => $this->service->close($item),
                'cancel' => fn () => $this->service->cancel($item),
            ];

            foreach ($operations as $label => $operation) {
                try {
                    $operation();
                    $this->fail("L’opération {$label} aurait dû être refusée sur un OF {$status->value}.");
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors(), "Aucune erreur métier pour {$label}.");
                }
            }

            $this->assertSame(1, $workOrder->items()->count());
            $this->assertSame(0, $item->fresh()->quantity_produced);
            $this->assertNotSame('Interdit', $item->fresh()->designation);
        }
    }

    public function test_delete_is_allowed_without_production_and_refused_after_production(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $deletable = $this->service->create($workOrder, $this->itemPayload(['mark' => 'DEL-01']));
        $this->service->delete($deletable, $admin);
        $this->assertNull(WorkOrderItem::query()->find($deletable->getKey()));

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::ItemDeleted->value,
        ]);

        $produced = $this->service->create($workOrder, $this->itemPayload(['mark' => 'DEL-02']));
        $produced = $this->service->recordProduction($produced, 1, 0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ne peut pas être supprimée');

        $this->service->delete($produced, $admin);
    }

    public function test_a_cancelled_item_cannot_be_deleted(): void
    {
        $item = WorkOrderItem::factory()->cancelled()->create();

        $this->expectException(ValidationException::class);

        $this->service->delete($item);
    }

    public function test_items_can_be_retrieved_by_work_order_and_by_id(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $other = WorkOrder::factory()->create();

        $second = WorkOrderItem::factory()->forWorkOrder($workOrder)->create(['mark' => 'B-02']);
        $first = WorkOrderItem::factory()->forWorkOrder($workOrder)->create(['mark' => 'A-01']);
        $foreign = WorkOrderItem::factory()->forWorkOrder($other)->create(['mark' => 'C-03']);

        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            $this->service->all($workOrder)->pluck('id')->all(),
        );

        $this->assertTrue($this->service->find($workOrder, $first->getKey())->is($first));

        $this->expectException(ValidationException::class);

        $this->service->find($workOrder, $foreign->getKey());
    }

    public function test_the_summary_aggregates_quantities_and_weight(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'SUM-01',
            'quantity_planned' => 10,
            'unit_weight_kg' => 2.500,
        ]));
        $produced = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'SUM-02',
            'quantity_planned' => 5,
            'unit_weight_kg' => 4,
        ]));
        $this->service->recordProduction($produced, 3, 1);

        $summary = $this->service->summary($workOrder);

        $this->assertSame(15, $summary->planned);
        $this->assertSame(3, $summary->produced);
        $this->assertSame(2, $summary->accepted);
        $this->assertSame(1, $summary->rejected);
        $this->assertSame(13, $summary->remaining);
        $this->assertEquals(45.0, $summary->totalWeightKg);
    }

    public function test_work_order_deletion_is_refused_when_an_import_history_exists(): void
    {
        $workOrder = WorkOrder::factory()->create();
        WorkOrderBomImport::factory()->forWorkOrder($workOrder)->create();

        try {
            $this->workOrders->delete($workOrder);

            $this->fail('La suppression aurait dû être refusée à cause de l’historique d’import.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'historique d\'import',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->getKey()]);
    }

    public function test_a_draft_work_order_without_import_history_can_still_be_deleted(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->workOrders->delete($workOrder);

        $this->assertDatabaseMissing('work_orders', ['id' => $workOrder->getKey()]);
    }

    public function test_work_order_deletion_is_refused_when_item_production_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $item = $this->service->create($workOrder, $this->itemPayload([
            'mark' => 'PROD-01',
            'quantity_planned' => 2,
        ]));
        $this->service->recordProduction($item, 1, 0, $admin);

        try {
            $this->workOrders->delete($workOrder);

            $this->fail('La suppression aurait dû être refusée à cause de la production des pièces.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'production ou des rebuts',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'mark' => 'P-01',
            'designation' => 'Poteau',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 1,
            'unit_weight_kg' => 10,
            'operation_group' => 'Débit',
            'notes' => null,
        ], $overrides);
    }
}
