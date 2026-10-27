<?php

namespace App\Models;

use App\Enums\DocumentVersionStatus;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'revision',
    'original_filename',
    'mime_type',
    'extension',
    'size_bytes',
    'sha256',
])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    /**
     * Lifecycle and storage fields are only written by DocumentVersionService.
     */
    public const PROTECTED_FIELDS = [
        'document_id',
        'version_number',
        'storage_disk',
        'storage_path',
        'status',
        'uploaded_by',
        'validated_by',
        'validated_at',
        'validation_comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentVersionStatus::class,
            'validated_at' => 'datetime',
            'size_bytes' => 'integer',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isApproved(): bool
    {
        return $this->status->isApproved();
    }

    public function canTransitionTo(DocumentVersionStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }

    public function downloadFilename(): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($this->original_filename, PATHINFO_FILENAME)) ?: 'document';

        return $base.'-'.$this->revision.'.'.$this->extension;
    }
}
