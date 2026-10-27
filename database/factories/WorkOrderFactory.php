<?php

namespace Database\Factories;

use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    /**
     * Factory numbers start at 9000 so they never collide with the numbers
     * produced by WorkOrderNumberGenerator (which starts at 0001) when a test
     * mixes factory records with records created through WorkOrderService.
     * Factories are test/dev data and do not advance the production counter.
     */
    protected static int $numberSequence = 9000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'number' => sprintf('OF-%d-%04d', now()->year, static::$numberSequence++),
            'title' => 'Fabrication '.fake()->words(3, true),
            'description' => null,
            'status' => WorkOrderStatus::Draft,
            'priority' => WorkOrderPriority::Normal,
            'planned_start_at' => now()->addDay(),
            'planned_end_at' => now()->addDays(10),
            'notes' => null,
        ];
    }

    public function status(WorkOrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function priority(WorkOrderPriority $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::Completed,
            'actual_start_at' => now()->subDays(5),
            'actual_end_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkOrderStatus::Cancelled,
        ]);
    }
}
