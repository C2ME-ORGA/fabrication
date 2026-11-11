<?php

namespace App\Models;

use App\Enums\ShipmentDocumentReviewDecision;
use App\Policies\ShipmentDocumentReviewPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'work_order_id',
    'shipment_id',
    'document_id',
    'document_version_id',
    'decision',
    'comment',
    'revision_snapshot',
    'decided_by',
    'decided_at',
    'created_at',
])]
#[UsePolicy(ShipmentDocumentReviewPolicy::class)]
class ShipmentDocumentReview extends Model
{
    /**
     * Décision append-only : aucune colonne updated_at n'est maintenue.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ShipmentDocumentReviewDecision::class,
            'revision_snapshot' => 'array',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Une décision de revue documentaire est immuable et ne peut pas être modifiée.',
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Une décision de revue documentaire est immuable et ne peut pas être supprimée.',
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
