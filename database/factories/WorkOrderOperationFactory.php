<?php

namespace Database\Factories;

use App\Enums\WorkshopOperationStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderOperation>
 */
class WorkOrderOperationFactory extends Factory
{
    protected $model = WorkOrderOperation::class;

    protected static int $codeSequence = 1;

    protected static int $positionSequence = 10;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'source_routing_operation_id' => null,
            'code' => sprintf('OF-OP-%04d', static::$codeSequence++),
            'name' => 'Opération '.fake()->words(2, true),
            'instructions' => null,
            'position' => static::$positionSequence++,
            'estimated_duration_minutes' => fake()->numberBetween(5, 120),
            'is_mandatory' => true,
            'status' => WorkshopOperationStatus::Pending,
        ];
    }

    public function forWorkOrder(WorkOrder $workOrder): static
    {
        return $this->state(fn (): array => [
            'work_order_id' => $workOrder->getKey(),
        ]);
    }

    public function status(WorkshopOperationStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn (): array => [
            'is_mandatory' => false,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkshopOperationStatus::Completed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }
}
