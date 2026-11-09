<?php

namespace App\Models;

use App\Enums\WorkshopOperationAuditEvent;
use App\Enums\WorkshopOperationStatus;
use App\Services\OperationTimeAggregationService;
use Database\Factories\WorkOrderOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Opération copiée d'une gamme atelier dans un ordre de fabrication.
 *
 * La copie est indépendante de la gamme modèle : code, nom, consignes,
 * durée estimée, caractère obligatoire et dépendances sont figés au moment
 * de la génération. Seul un service métier peut modifier l'état ou les
 * données protégées.
 */
#[Fillable([
    'code',
    'name',
    'instructions',
    'position',
    'estimated_duration_minutes',
    'is_mandatory',
])]
class WorkOrderOperation extends Model
{
    /** @use HasFactory<WorkOrderOperationFactory> */
    use HasFactory;

    /**
     * Champs techniques écrits uniquement par les services métier.
     */
    public const PROTECTED_FIELDS = [
        'work_order_id',
        'source_routing_operation_id',
        'status',
        'blocked_reason',
        'blocked_at',
        'blocked_by',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'is_mandatory' => 'boolean',
            'status' => WorkshopOperationStatus::class,
            'blocked_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * Opération modèle dont cette opération est issue, si elle existe encore.
     *
     * @return BelongsTo<WorkshopRoutingOperation, $this>
     */
    public function sourceRoutingOperation(): BelongsTo
    {
        return $this->belongsTo(WorkshopRoutingOperation::class, 'source_routing_operation_id');
    }

    /**
     * Opérations dont celle-ci dépend.
     *
     * @return BelongsToMany<self, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'work_order_operation_dependencies',
            'work_order_operation_id',
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
            'work_order_operation_dependencies',
            'depends_on_operation_id',
            'work_order_operation_id',
        )->withTimestamps();
    }

    /**
     * @return HasMany<OperationTimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(OperationTimeEntry::class)->orderBy('started_at')->orderBy('id');
    }

    /**
     * @return HasMany<OperationTimeEntry, $this>
     */
    public function activeTimeEntries(): HasMany
    {
        return $this->hasMany(OperationTimeEntry::class)->whereNull('ended_at');
    }

    /**
     * @return HasMany<WorkOrderOperationAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(WorkOrderOperationAuditLog::class)->latest('created_at');
    }

    /**
     * Dernière entrée d'audit correspondant à une transition de statut.
     * Permet d'afficher l'utilisateur responsable de la dernière action.
     *
     * @return HasOne<WorkOrderOperationAuditLog, $this>
     */
    public function lastStatusAudit(): HasOne
    {
        return $this->hasOne(WorkOrderOperationAuditLog::class, 'work_order_operation_id')
            ->whereIn('event', [
                WorkshopOperationAuditEvent::Ready->value,
                WorkshopOperationAuditEvent::Started->value,
                WorkshopOperationAuditEvent::Paused->value,
                WorkshopOperationAuditEvent::Resumed->value,
                WorkshopOperationAuditEvent::Blocked->value,
                WorkshopOperationAuditEvent::Unblocked->value,
                WorkshopOperationAuditEvent::Completed->value,
                WorkshopOperationAuditEvent::Cancelled->value,
                WorkshopOperationAuditEvent::TimerStarted->value,
                WorkshopOperationAuditEvent::TimerStopped->value,
                WorkshopOperationAuditEvent::TimerAutoStopped->value,
                WorkshopOperationAuditEvent::ManualTimeRecorded->value,
                WorkshopOperationAuditEvent::TimeCorrected->value,
            ])
            ->latestOfMany();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isBlocked(): bool
    {
        return $this->status === WorkshopOperationStatus::Blocked;
    }

    /**
     * Toutes les dépendances sont-elles terminées ?
     */
    public function dependenciesSatisfied(): bool
    {
        return ! $this->dependencies()
            ->where('work_order_operations.status', '!=', WorkshopOperationStatus::Completed->value)
            ->exists();
    }

    /**
     * Somme des durées effectives (corrections incluses), en secondes.
     */
    public function recordedDurationSeconds(): int
    {
        $entries = $this->relationLoaded('timeEntries')
            ? $this->timeEntries
            : $this->timeEntries()->get();

        return app(OperationTimeAggregationService::class)
            ->realSecondsFromEntries($entries);
    }

    /**
     * Une opération peut être démarrée uniquement si elle est prête, non
     * finale et si toutes ses dépendances sont terminées.
     */
    public function canStart(): bool
    {
        return $this->status === WorkshopOperationStatus::Ready
            && $this->dependenciesSatisfied();
    }

    public function scopeMandatory(Builder $query): void
    {
        $query->where('is_mandatory', true);
    }

    public function scopeForWorkOrder(Builder $query, WorkOrder|int $workOrder): void
    {
        $query->where('work_order_id', $workOrder instanceof WorkOrder ? $workOrder->getKey() : $workOrder);
    }
}
