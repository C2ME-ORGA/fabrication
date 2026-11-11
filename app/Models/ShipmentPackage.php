<?php

namespace App\Models;

use App\Enums\ShipmentPackageType;
use App\Policies\ShipmentPackagePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'shipment_id',
    'code',
    'label',
    'package_type',
    'position',
    'weight_kg',
    'length_cm',
    'width_cm',
    'height_cm',
    'seal_number',
    'notes',
])]
#[UsePolicy(ShipmentPackagePolicy::class)]
class ShipmentPackage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_type' => ShipmentPackageType::class,
            'position' => 'integer',
            'weight_kg' => 'decimal:3',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
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
     * @return HasMany<ShipmentPackageLine, $this>
     */
    public function packageLines(): HasMany
    {
        return $this->hasMany(ShipmentPackageLine::class)->orderBy('id');
    }

    /**
     * @return BelongsToMany<ShipmentLine, $this>
     */
    public function lines(): BelongsToMany
    {
        return $this->belongsToMany(ShipmentLine::class, 'shipment_package_lines')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function contentSummary(): string
    {
        $lines = $this->packageLines()
            ->with('shipmentLine')
            ->get()
            ->map(fn (ShipmentPackageLine $line): string => $line->shipmentLine->mark_snapshot.' × '.$line->quantity)
            ->all();

        return $lines === [] ? '—' : implode(', ', $lines);
    }
}
