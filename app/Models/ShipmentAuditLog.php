<?php

namespace App\Models;

use App\Enums\ShipmentAuditEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'shipment_id',
    'work_order_id',
    'shipment_number',
    'work_order_number',
    'user_id',
    'event',
    'from_status',
    'to_status',
    'comment',
    'metadata',
])]
class ShipmentAuditLog extends Model
{
    /**
     * Journal immuable : aucune colonne updated_at n'est maintenue.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => ShipmentAuditEvent::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'expédition sont immuables et ne peuvent pas être modifiées.',
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'expédition sont immuables et ne peuvent pas être supprimées.',
        );
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
