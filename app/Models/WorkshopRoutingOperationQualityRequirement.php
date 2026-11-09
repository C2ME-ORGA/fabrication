<?php

namespace App\Models;

use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use Database\Factories\WorkshopRoutingOperationQualityRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exigence qualité portée par une opération de gamme modèle.
 *
 * L'exigence référence l'identité d'un modèle (et non une version) : la
 * version active est résolue au moment de la génération du plan qualité de
 * l'OF, puis copiée. Une exigence ne modifie jamais un OF déjà généré.
 */
#[Fillable(['scope', 'trigger', 'is_mandatory', 'position'])]
class WorkshopRoutingOperationQualityRequirement extends Model
{
    /** @use HasFactory<WorkshopRoutingOperationQualityRequirementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => QualityRequirementScope::class,
            'trigger' => QualityRequirementTrigger::class,
            'is_mandatory' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkshopRoutingOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkshopRoutingOperation::class, 'workshop_routing_operation_id');
    }

    /**
     * @return BelongsTo<QualityControlTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QualityControlTemplate::class, 'quality_control_template_id');
    }
}
