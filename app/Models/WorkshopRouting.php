<?php

namespace App\Models;

use Database\Factories\WorkshopRoutingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Gamme atelier modèle, réutilisable par plusieurs ordres de fabrication.
 *
 * La gamme est un catalogue : l'affectation à un OF copie ses opérations dans
 * des enregistrements indépendants (voir WorkOrderOperation). Une modification
 * ultérieure de la gamme n'altère donc jamais un OF déjà généré.
 */
#[Fillable(['code', 'name', 'description', 'is_active'])]
class WorkshopRouting extends Model
{
    /** @use HasFactory<WorkshopRoutingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Opérations de la gamme, dans l'ordre d'affichage.
     *
     * @return HasMany<WorkshopRoutingOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(WorkshopRoutingOperation::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Toutes les dépendances entre opérations de cette gamme.
     *
     * @return HasManyThrough<WorkshopRoutingOperationDependency, WorkshopRoutingOperation, $this>
     */
    public function operationDependencies(): HasManyThrough
    {
        return $this->hasManyThrough(
            WorkshopRoutingOperationDependency::class,
            WorkshopRoutingOperation::class,
            'workshop_routing_id',
            'workshop_routing_operation_id',
            'id',
            'id',
        );
    }

    /**
     * OF ayant utilisé cette gamme.
     *
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Exigences qualité portées par les opérations de cette gamme.
     *
     * @return HasManyThrough<WorkshopRoutingOperationQualityRequirement, WorkshopRoutingOperation, $this>
     */
    public function qualityRequirements(): HasManyThrough
    {
        return $this->hasManyThrough(
            WorkshopRoutingOperationQualityRequirement::class,
            WorkshopRoutingOperation::class,
            'workshop_routing_id',
            'workshop_routing_operation_id',
            'id',
            'id',
        );
    }

    /**
     * La gamme a-t-elle déjà servi à générer des opérations d'OF ?
     */
    public function hasGeneratedOperations(): bool
    {
        return WorkOrderOperation::query()
            ->whereIn('source_routing_operation_id', $this->operations()->select('id'))
            ->exists();
    }

    public function isUsedByWorkOrders(): bool
    {
        return $this->workOrders()->exists();
    }

    public function estimatedDurationTotal(): int
    {
        return (int) $this->operations()->sum('estimated_duration_minutes');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
