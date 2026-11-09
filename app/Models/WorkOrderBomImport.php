<?php

namespace App\Models;

use App\Enums\WorkOrderBomImportStatus;
use Database\Factories\WorkOrderBomImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_order_id',
    'original_filename',
    'storage_disk',
    'storage_path',
    'sha256',
    'column_mapping',
    'rows_total',
    'rows_valid',
    'rows_invalid',
    'imported_by',
    'imported_at',
    'status',
])]
class WorkOrderBomImport extends Model
{
    /** @use HasFactory<WorkOrderBomImportFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'rows_total' => 'integer',
            'rows_valid' => 'integer',
            'rows_invalid' => 'integer',
            'imported_at' => 'datetime',
            'status' => WorkOrderBomImportStatus::class,
        ];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
