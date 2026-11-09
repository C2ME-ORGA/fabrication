<?php

namespace Database\Factories;

use App\Enums\WorkOrderBomImportStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrderBomImport>
 */
class WorkOrderBomImportFactory extends Factory
{
    protected $model = WorkOrderBomImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'original_filename' => 'nomenclature.csv',
            'storage_disk' => 'bom_imports',
            'storage_path' => 'bom_imports/'.Str::uuid()->toString().'.csv',
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'column_mapping' => [
                'mark' => 'mark',
                'designation' => 'designation',
                'profile' => 'profile',
                'steel_grade' => 'steel_grade',
                'length_mm' => 'length_mm',
                'quantity_planned' => 'quantity_planned',
                'unit_weight_kg' => 'unit_weight_kg',
                'operation_group' => 'operation_group',
                'notes' => 'notes',
            ],
            'rows_total' => 3,
            'rows_valid' => 3,
            'rows_invalid' => 0,
            'imported_by' => User::factory(),
            'imported_at' => now(),
            'status' => WorkOrderBomImportStatus::Completed,
        ];
    }

    public function forWorkOrder(WorkOrder $workOrder): static
    {
        return $this->state(fn (): array => ['work_order_id' => $workOrder->getKey()]);
    }

    public function importedBy(User $user): static
    {
        return $this->state(fn (): array => ['imported_by' => $user->getKey()]);
    }
}
