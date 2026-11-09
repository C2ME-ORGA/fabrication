<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de dépendance entre deux opérations d'une même gamme atelier.
 *
 * `operation` dépend de `dependsOnOperation`, qui doit être terminée avant
 * qu'elle puisse démarrer. Le modèle existe pour exposer les dépendances dans
 * une relation Filament dédiée ; les validations restent dans le service.
 */
#[Fillable([
    'workshop_routing_operation_id',
    'depends_on_operation_id',
])]
class WorkshopRoutingOperationDependency extends Model
{
    /**
     * @return BelongsTo<WorkshopRoutingOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkshopRoutingOperation::class, 'workshop_routing_operation_id');
    }

    /**
     * @return BelongsTo<WorkshopRoutingOperation, $this>
     */
    public function dependsOnOperation(): BelongsTo
    {
        return $this->belongsTo(WorkshopRoutingOperation::class, 'depends_on_operation_id');
    }
}
