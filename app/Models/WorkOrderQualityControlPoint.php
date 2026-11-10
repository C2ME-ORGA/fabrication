<?php

namespace App\Models;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use Database\Factories\WorkOrderQualityControlPointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Point de contrôle figé dans un ordre de fabrication.
 *
 * Les paramètres issus du référentiel sont immuables. Seuls le nombre de
 * résultats attendus et la sélection des repères peuvent être recalculés par
 * le service, dans le cadre audité prévu par la conception.
 */
#[Fillable([])]
class WorkOrderQualityControlPoint extends Model
{
    /** @use HasFactory<WorkOrderQualityControlPointFactory> */
    use HasFactory;

    /**
     * @var array<int, string>
     */
    public const SNAPSHOT_FIELDS = [
        'work_order_quality_control_id',
        'position',
        'label',
        'instructions',
        'result_type',
        'unit',
        'tolerance_min',
        'tolerance_max',
        'is_mandatory',
        'is_critical',
        'allow_deviation',
        'sampling_mode',
        'sampling_every',
        'options',
    ];

    /**
     * @var array<int, string>
     */
    public const RECALCULABLE_FIELDS = [
        'required_results',
        'sampled_item_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'result_type' => QualityControlResultType::class,
            'tolerance_min' => 'decimal:4',
            'tolerance_max' => 'decimal:4',
            'is_mandatory' => 'boolean',
            'is_critical' => 'boolean',
            'allow_deviation' => 'boolean',
            'sampling_mode' => QualityControlSamplingMode::class,
            'sampling_every' => 'integer',
            'required_results' => 'integer',
            'sampled_item_ids' => 'array',
            'options' => 'array',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        $immutableChanges = array_diff(
            array_keys($this->getDirty()),
            self::RECALCULABLE_FIELDS,
        );

        if (array_intersect($immutableChanges, self::SNAPSHOT_FIELDS) !== []) {
            throw new RuntimeException(
                'Les paramètres figés d\'un point de contrôle généré ne peuvent pas être modifiés.'
            );
        }

        parent::performUpdate($query);
    }

    /**
     * @return BelongsTo<WorkOrderQualityControl, $this>
     */
    public function control(): BelongsTo
    {
        return $this->belongsTo(WorkOrderQualityControl::class, 'work_order_quality_control_id');
    }

    /**
     * @return HasMany<QualityControlResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(QualityControlResult::class, 'work_order_quality_control_point_id')
            ->orderBy('id');
    }

    public function requiredValidations(): int
    {
        return $this->is_critical
            ? (int) config('quality.controls.required_validations_critical', 2)
            : (int) config('quality.controls.required_validations_default', 1);
    }

    /**
     * @return array<int, int>
     */
    public function sampledItemIds(): array
    {
        return array_values(array_map(
            fn (mixed $id): int => (int) $id,
            $this->sampled_item_ids ?? [],
        ));
    }

    public function usesDeterministicSelection(): bool
    {
        return in_array($this->sampling_mode, [
            QualityControlSamplingMode::All,
            QualityControlSamplingMode::EveryN,
        ], true);
    }
}
