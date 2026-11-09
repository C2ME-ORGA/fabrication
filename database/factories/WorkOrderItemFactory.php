<?php

namespace Database\Factories;

use App\Enums\WorkOrderItemStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderItem>
 */
class WorkOrderItemFactory extends Factory
{
    protected $model = WorkOrderItem::class;

    protected static int $markSequence = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'mark' => sprintf('REP-%04d', static::$markSequence++),
            'designation' => 'Poteau '.fake()->word(),
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 10,
            'quantity_produced' => 0,
            'quantity_rejected' => 0,
            'unit_weight_kg' => 22.400,
            'operation_group' => 'Débit',
            'status' => WorkOrderItemStatus::Todo,
            'notes' => null,
        ];
    }

    public function forWorkOrder(WorkOrder $workOrder): static
    {
        return $this->state(fn (): array => ['work_order_id' => $workOrder->getKey()]);
    }

    public function partiallyProduced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_produced' => max(1, intdiv((int) ($attributes['quantity_planned'] ?? 1), 2)),
            'quantity_rejected' => 0,
            'status' => WorkOrderItemStatus::PartiallyProduced,
        ]);
    }

    public function produced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_produced' => (int) ($attributes['quantity_planned'] ?? 1),
            'quantity_rejected' => 0,
            'status' => WorkOrderItemStatus::Produced,
        ]);
    }

    public function closed(): static
    {
        return $this->produced()->state(fn (): array => [
            'status' => WorkOrderItemStatus::Closed,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderItemStatus::Cancelled,
        ]);
    }
}
