<?php

namespace App\Models;

use Database\Factories\WorkshopRoutingOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Opération d'une gamme atelier modèle.
 *
 * Les dépendances forment un graphe orienté : l'opération ne peut démarrer
 * que lorsque toutes les opérations dont elle dépend sont terminées. Les
 * cycles sont refusés par WorkshopRoutingService.
 */
#[Fillable([
    'code',
    'name',
    'instructions',
    'position',
    'estimated_duration_minutes',
    'is_mandatory',
])]
class WorkshopRoutingOperation extends Model
{
    /** @use HasFactory<WorkshopRoutingOperationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'is_mandatory' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkshopRouting, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(WorkshopRouting::class, 'workshop_routing_id');
    }

    /**
     * Opérations qui doivent être terminées avant celle-ci.
     *
     * @return BelongsToMany<self, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'workshop_routing_operation_dependencies',
            'workshop_routing_operation_id',
            'depends_on_operation_id',
        )->withTimestamps();
    }

    /**
     * Opérations qui dépendent de celle-ci.
     *
     * @return BelongsToMany<self, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'workshop_routing_operation_dependencies',
            'depends_on_operation_id',
            'workshop_routing_operation_id',
        )->withTimestamps();
    }
}
