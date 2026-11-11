<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shipment_package_id',
    'shipment_line_id',
    'quantity',
])]
class ShipmentPackageLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShipmentPackage, $this>
     */
    public function shipmentPackage(): BelongsTo
    {
        return $this->belongsTo(ShipmentPackage::class);
    }

    /**
     * @return BelongsTo<ShipmentLine, $this>
     */
    public function shipmentLine(): BelongsTo
    {
        return $this->belongsTo(ShipmentLine::class);
    }
}
