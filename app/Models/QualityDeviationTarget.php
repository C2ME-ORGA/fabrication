<?php

namespace App\Models;

use Database\Factories\QualityDeviationTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cible précise d'une dérogation.
 */
#[Fillable([])]
class QualityDeviationTarget extends Model
{
    /** @use HasFactory<QualityDeviationTargetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<QualityDeviation, $this>
     */
    public function deviation(): BelongsTo
    {
        return $this->belongsTo(QualityDeviation::class, 'quality_deviation_id');
    }

    /**
     * @return BelongsTo<WorkOrderQualityControl, $this>
     */
    public function control(): BelongsTo
    {
        return $this->belongsTo(WorkOrderQualityControl::class, 'work_order_quality_control_id');
    }

    /**
     * @return BelongsTo<WorkOrderQualityControlPoint, $this>
     */
    public function point(): BelongsTo
    {
        return $this->belongsTo(WorkOrderQualityControlPoint::class, 'work_order_quality_control_point_id');
    }

    /**
     * @return BelongsTo<NonConformity, $this>
     */
    public function nonConformity(): BelongsTo
    {
        return $this->belongsTo(NonConformity::class);
    }

    public function describe(): string
    {
        if ($this->work_order_quality_control_id !== null) {
            $control = $this->control;

            return $control === null
                ? "Contrôle #{$this->work_order_quality_control_id}"
                : "Contrôle {$control->template_code} — {$control->template_name}";
        }

        if ($this->work_order_quality_control_point_id !== null) {
            $point = $this->point;

            return $point === null
                ? "Point #{$this->work_order_quality_control_point_id}"
                : "Point « {$point->label} »";
        }

        if ($this->non_conformity_id !== null) {
            $nonConformity = $this->nonConformity;

            return $nonConformity === null
                ? "NC #{$this->non_conformity_id}"
                : "Non-conformité {$nonConformity->reference}";
        }

        return '—';
    }
}
