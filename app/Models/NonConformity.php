<?php

namespace App\Models;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformityOrigin;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\NonConformityVerificationType;
use Database\Factories\NonConformityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Non-conformité qualité.
 *
 * Le snapshot initial de politique est immuable. Le snapshot courant sert de
 * référence pour les vérifications et la clôture ; il n'est mis à jour que
 * par une requalification explicite et auditée, jamais par une évolution de
 * config/quality.php.
 */
#[Fillable([])]
class NonConformity extends Model
{
    /** @use HasFactory<NonConformityFactory> */
    use HasFactory;

    /**
     * Champs techniques écrits uniquement par les services métier.
     *
     * @var array<int, string>
     */
    public const PROTECTED_FIELDS = [
        'reference',
        'origin',
        'work_order_id',
        'work_order_number',
        'work_order_item_id',
        'work_order_operation_id',
        'work_order_quality_control_id',
        'quality_control_result_id',
        'unique_result_id',
        'status',
        'is_blocking',
        'verification_required',
        'counter_control_required',
        'severity_policy_snapshot',
        'detected_by',
        'created_by',
        'qualified_by',
        'qualified_at',
        'taken_in_charge_at',
        'treated_at',
        'closed_at',
        'closed_by',
        'closure_comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => NonConformityOrigin::class,
            'severity' => NonConformitySeverity::class,
            'initial_severity' => NonConformitySeverity::class,
            'category' => NonConformityCategory::class,
            'status' => NonConformityStatus::class,
            'is_blocking' => 'boolean',
            'verification_required' => 'boolean',
            'counter_control_required' => 'boolean',
            'severity_policy_snapshot' => 'array',
            'current_severity_policy_snapshot' => 'array',
            'detected_at' => 'datetime',
            'due_date' => 'date',
            'containment_at' => 'datetime',
            'qualified_at' => 'datetime',
            'taken_in_charge_at' => 'datetime',
            'treated_at' => 'datetime',
            'verification_due_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<WorkOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'work_order_item_id');
    }

    /**
     * @return BelongsTo<WorkOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'work_order_operation_id');
    }

    /**
     * @return BelongsTo<WorkOrderQualityControl, $this>
     */
    public function control(): BelongsTo
    {
        return $this->belongsTo(WorkOrderQualityControl::class, 'work_order_quality_control_id');
    }

    /**
     * @return BelongsTo<QualityControlResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(QualityControlResult::class, 'quality_control_result_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function detector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detected_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function qualifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<NonConformityStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(NonConformityStatusHistory::class)->orderBy('id');
    }

    /**
     * @return HasMany<NonConformityCorrectiveAction, $this>
     */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(NonConformityCorrectiveAction::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<NonConformityVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(NonConformityVerification::class)->orderBy('id');
    }

    /**
     * @return HasMany<NonConformityAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(NonConformityAttachment::class)->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->status === NonConformityStatus::Closed;
    }

    public function isCancelled(): bool
    {
        return $this->status === NonConformityStatus::Cancelled;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isMajorOrCritical(): bool
    {
        return $this->severity->requiresQualityManager();
    }

    /**
     * Politique effectivement appliquée à la gravité courante.
     *
     * Les NC créées avant l'introduction du snapshot courant retombent sur le
     * snapshot initial.
     *
     * @return array<string, mixed>
     */
    public function currentPolicy(): array
    {
        return $this->current_severity_policy_snapshot
            ?? $this->severity_policy_snapshot
            ?? [];
    }

    public function latestVerification(NonConformityVerificationType $type): ?NonConformityVerification
    {
        return $this->verifications()
            ->where('type', $type->value)
            ->latest('id')
            ->first();
    }

    public function scopeOpenForTreatment(Builder $query): void
    {
        $query->whereIn('status', [
            NonConformityStatus::Open->value,
            NonConformityStatus::InProgress->value,
        ]);
    }
}
