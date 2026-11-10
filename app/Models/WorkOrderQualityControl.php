<?php

namespace App\Models;

use App\Enums\QualityControlStatus;
use App\Enums\QualityControlType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Policies\QualityControlPolicy;
use Database\Factories\WorkOrderQualityControlFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use RuntimeException;

/**
 * Contrôle qualité généré dans un ordre de fabrication.
 *
 * Toutes les données du modèle et de l'exigence sont copiées à la génération.
 * Les champs de photographie sont immuables : seul le statut calculé et les
 * informations de clôture peuvent évoluer via les services métier.
 */
#[Fillable([])]
#[UsePolicy(QualityControlPolicy::class)]
class WorkOrderQualityControl extends Model
{
    /** @use HasFactory<WorkOrderQualityControlFactory> */
    use HasFactory;

    /**
     * Champs figés à la génération. Une écriture directe lève une exception.
     *
     * @var array<int, string>
     */
    public const SNAPSHOT_FIELDS = [
        'work_order_id',
        'work_order_operation_id',
        'work_order_item_id',
        'source_requirement_id',
        'source_type',
        'template_code',
        'template_name',
        'template_version_number',
        'control_type',
        'scope',
        'trigger',
        'is_mandatory',
        'allow_deviation',
        'position',
        'snapshot_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version_number' => 'integer',
            'control_type' => QualityControlType::class,
            'scope' => QualityRequirementScope::class,
            'trigger' => QualityRequirementTrigger::class,
            'is_mandatory' => 'boolean',
            'allow_deviation' => 'boolean',
            'position' => 'integer',
            'status' => QualityControlStatus::class,
            'required_by_at' => 'datetime',
            'completed_at' => 'datetime',
            'snapshot_metadata' => 'array',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        $dirtySnapshotFields = array_intersect(array_keys($this->getDirty()), self::SNAPSHOT_FIELDS);

        if ($dirtySnapshotFields !== []) {
            throw new RuntimeException(
                'Les paramètres figés d\'un contrôle qualité généré ne peuvent pas être modifiés.'
            );
        }

        parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->hasResults()) {
            throw new RuntimeException(
                'Un contrôle qualité portant des résultats ne peut pas être supprimé.'
            );
        }

        parent::performDeleteOnModel();
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<WorkOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'work_order_operation_id');
    }

    /**
     * @return BelongsTo<WorkOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'work_order_item_id');
    }

    /**
     * @return BelongsTo<WorkshopRoutingOperationQualityRequirement, $this>
     */
    public function sourceRequirement(): BelongsTo
    {
        return $this->belongsTo(
            WorkshopRoutingOperationQualityRequirement::class,
            'source_requirement_id',
        );
    }

    /**
     * @return HasMany<WorkOrderQualityControlPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(WorkOrderQualityControlPoint::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasManyThrough<QualityControlResult, WorkOrderQualityControlPoint, $this>
     */
    public function results(): HasManyThrough
    {
        return $this->hasManyThrough(
            QualityControlResult::class,
            WorkOrderQualityControlPoint::class,
            'work_order_quality_control_id',
            'work_order_quality_control_point_id',
        );
    }

    /**
     * Non-conformités rattachées à ce contrôle.
     *
     * @return HasMany<NonConformity, $this>
     */
    public function nonConformities(): HasMany
    {
        return $this->hasMany(NonConformity::class, 'work_order_quality_control_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function hasResults(): bool
    {
        return $this->results()->exists();
    }

    public function isCancelled(): bool
    {
        return $this->status === QualityControlStatus::Cancelled;
    }

    public function allowsResultEntry(): bool
    {
        return $this->status->allowsResultEntry();
    }

    public function scopeForWorkOrder(Builder $query, WorkOrder|int $workOrder): void
    {
        $query->where('work_order_id', $workOrder instanceof WorkOrder ? $workOrder->getKey() : $workOrder);
    }
}
