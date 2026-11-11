<?php

namespace App\Models;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

#[Fillable([
    'work_order_id',
    'shipment_id',
    'type',
    'scope_key',
    'version',
    'reference',
    'status',
    'disk',
    'path',
    'original_filename',
    'mime_type',
    'size_bytes',
    'sha256',
    'template_version',
    'source_data_hash',
    'of_revision',
    'revision_snapshot',
    'visa_snapshot',
    'generated_by',
    'generated_at',
    'supersedes_id',
    'superseded_at',
    'notes',
])]
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use HasFactory;

    /**
     * Champs de contenu figés après archivage. Seuls le statut, la
     * supersession, la date de supersession et la note peuvent évoluer.
     */
    public const IMMUTABLE_FIELDS = [
        'work_order_id',
        'shipment_id',
        'type',
        'scope_key',
        'version',
        'reference',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'template_version',
        'source_data_hash',
        'of_revision',
        'revision_snapshot',
        'visa_snapshot',
        'generated_by',
        'generated_at',
        'supersedes_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GeneratedDocumentType::class,
            'status' => GeneratedDocumentStatus::class,
            'version' => 'integer',
            'size_bytes' => 'integer',
            'generated_at' => 'datetime',
            'superseded_at' => 'datetime',
            'revision_snapshot' => 'array',
            'visa_snapshot' => 'array',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        $dirty = array_intersect(array_keys($this->getDirty()), self::IMMUTABLE_FIELDS);

        if ($dirty !== []) {
            throw new RuntimeException(
                'Le contenu d\'un document PDF archivé ne peut pas être modifié.'
            );
        }

        parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Un document PDF archivé ne peut pas être supprimé.'
        );
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function isCurrent(): bool
    {
        return $this->status === GeneratedDocumentStatus::Archived;
    }
}
