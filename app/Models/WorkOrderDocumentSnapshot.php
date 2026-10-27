<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\DocumentSnapshotReason;
use Database\Factories\WorkOrderDocumentSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_order_id',
    'document_id',
    'document_version_id',
    'category',
    'title',
    'reference',
    'revision',
    'version_number',
    'sha256',
    'validated_by',
    'validated_at',
    'captured_by',
    'captured_at',
    'reason',
    'comment',
])]
class WorkOrderDocumentSnapshot extends Model
{
    /** @use HasFactory<WorkOrderDocumentSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'reason' => DocumentSnapshotReason::class,
            'validated_at' => 'datetime',
            'captured_at' => 'datetime',
            'version_number' => 'integer',
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
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
