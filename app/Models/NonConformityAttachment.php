<?php

namespace App\Models;

use App\Policies\NonConformityAttachmentPolicy;
use Database\Factories\NonConformityAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preuve jointe à une non-conformité.
 */
#[Fillable([])]
#[UsePolicy(NonConformityAttachmentPolicy::class)]
class NonConformityAttachment extends Model
{
    /** @use HasFactory<NonConformityAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NonConformity, $this>
     */
    public function nonConformity(): BelongsTo
    {
        return $this->belongsTo(NonConformity::class);
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
