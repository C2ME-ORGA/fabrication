<?php

namespace App\Models;

use App\Policies\ShipmentLinePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'shipment_id',
    'work_order_item_id',
    'mark_snapshot',
    'designation_snapshot',
    'quantity_prepared',
    'quantity_shipped',
    'unit_weight_kg_snapshot',
    'notes',
])]
#[UsePolicy(ShipmentLinePolicy::class)]
class ShipmentLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_prepared' => 'integer',
            'quantity_shipped' => 'integer',
            'unit_weight_kg_snapshot' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<WorkOrderItem, $this>
     */
    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    /**
     * @return HasMany<ShipmentPackageLine, $this>
     */
    public function packageLines(): HasMany
    {
        return $this->hasMany(ShipmentPackageLine::class);
    }

    public function packagedQuantity(): int
    {
        return (int) $this->packageLines()->sum('quantity');
    }

    public function remainingToPackage(): int
    {
        return max(0, $this->quantity_prepared - $this->packagedQuantity());
    }
}
