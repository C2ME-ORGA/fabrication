<?php

namespace App\Models;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\ShipmentStatus;
use App\Enums\WorkOrderStatus;
use App\Policies\ShipmentPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'work_order_id',
    'number',
    'status',
    'planned_ship_at',
    'actual_ship_at',
    'carrier_name',
    'carrier_reference',
    'delivery_note_reference',
    'delivery_address',
    'notes',
    'prepared_by',
    'prepared_at',
    'validated_by',
    'validated_at',
    'shipped_by',
    'cancelled_by',
    'cancelled_at',
    'cancellation_reason',
    'created_by',
])]
#[UsePolicy(ShipmentPolicy::class)]
class Shipment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'planned_ship_at' => 'datetime',
            'actual_ship_at' => 'datetime',
            'prepared_at' => 'datetime',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return HasMany<ShipmentPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<ShipmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<ShipmentAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ShipmentAuditLog::class)->latest('created_at')->latest('id');
    }

    /**
     * Décisions P7 visibles pour cette expédition : décisions au niveau OF
     * et décisions prises depuis cette expédition.
     *
     * @return HasMany<ShipmentDocumentReview, $this>
     */
    public function documentReviews(): HasMany
    {
        return $this->hasMany(ShipmentDocumentReview::class, 'work_order_id', 'work_order_id')
            ->where(function (Builder $query): void {
                $query->whereNull('shipment_id')
                    ->orWhere('shipment_id', $this->getKey());
            })
            ->latest('decided_at')
            ->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
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
    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class)->latest('generated_at')->latest('id');
    }

    public function latestDeliveryNote(): ?GeneratedDocument
    {
        return $this->generatedDocuments()
            ->where('type', GeneratedDocumentType::DeliveryNote->value)
            ->where('status', GeneratedDocumentStatus::Archived->value)
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Un OF annulé est final : aucune préparation ne doit plus le modifier.
     * Les autres statuts conservent les règles existantes du Lot 3.
     */
    public function allowsMutation(): bool
    {
        return $this->workOrder?->status !== WorkOrderStatus::Cancelled;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable() && $this->allowsMutation();
    }

    public function canReopen(): bool
    {
        return $this->status === ShipmentStatus::Prepared && $this->allowsMutation();
    }

    public function canBeValidated(): bool
    {
        return $this->status === ShipmentStatus::Prepared
            && $this->workOrder?->status === WorkOrderStatus::Completed;
    }

    public function canBeShipped(): bool
    {
        return $this->status === ShipmentStatus::Validated && $this->allowsMutation();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            ShipmentStatus::Draft,
            ShipmentStatus::Prepared,
            ShipmentStatus::Validated,
        ], true);
    }
}
