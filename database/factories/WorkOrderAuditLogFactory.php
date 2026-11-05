<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderAuditLog>
 */
class WorkOrderAuditLogFactory extends Factory
{
    protected $model = WorkOrderAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'user_id' => User::factory(),
            'event' => 'status_changed',
            'from_status' => 'draft',
            'to_status' => 'ready',
            'comment' => null,
            'metadata' => null,
        ];
    }
}
