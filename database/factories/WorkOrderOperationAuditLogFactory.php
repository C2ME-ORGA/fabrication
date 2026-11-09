<?php

namespace Database\Factories;

use App\Enums\WorkshopOperationAuditEvent;
use App\Models\User;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderOperationAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderOperationAuditLog>
 */
class WorkOrderOperationAuditLogFactory extends Factory
{
    protected $model = WorkOrderOperationAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_operation_id' => WorkOrderOperation::factory(),
            'work_order_id' => null,
            'work_order_number' => null,
            'operation_code' => null,
            'user_id' => User::factory(),
            'event' => WorkshopOperationAuditEvent::Created->value,
            'from_status' => null,
            'to_status' => null,
            'comment' => null,
            'metadata' => null,
        ];
    }
}
