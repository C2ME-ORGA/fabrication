<?php

namespace App\Models;

use App\Enums\QualityResultDecision;
use Database\Factories\QualityControlResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Résultat d'un point de contrôle généré.
 *
 * Le résultat est append-only : la correction passe par un nouveau résultat
 * référençant l'ancien (`supersedes_result_id`), jamais par une modification.
 */
#[Fillable([])]
class QualityControlResult extends Model
{
    /** @use HasFactory<QualityControlResultFactory> */
    use HasFactory;

    /**
     * Aucune colonne updated_at : l'entrée est immuable.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'value_boolean' => 'boolean',
            'value_numeric' => 'decimal:4',
            'tolerance_min_snapshot' => 'decimal:4',
            'tolerance_max_snapshot' => 'decimal:4',
            'is_conform' => 'boolean',
            'evaluation_detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Un résultat de contrôle qualité est immuable et ne peut pas être modifié.'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Un résultat de contrôle qualité est immuable et ne peut pas être supprimé.'
        );
    }

    /**
     * @return BelongsTo<WorkOrderQualityControlPoint, $this>
     */
    public function point(): BelongsTo
    {
        return $this->belongsTo(
            WorkOrderQualityControlPoint::class,
            'work_order_quality_control_point_id',
        );
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
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_result_id');
    }

    /**
     * @return HasMany<QualityControlResultValidation, $this>
     */
    public function validations(): HasMany
    {
        return $this->hasMany(QualityControlResultValidation::class)->orderBy('id');
    }

    /**
     * @return HasMany<QualityControlResultAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(QualityControlResultAttachment::class);
    }

    /**
     * @return HasMany<NonConformity, $this>
     */
    public function nonConformities(): HasMany
    {
        return $this->hasMany(NonConformity::class, 'quality_control_result_id');
    }

    public function isRejected(): bool
    {
        return $this->validations->contains(
            fn (QualityControlResultValidation $validation): bool => $validation->decision === QualityResultDecision::Rejected,
        );
    }

    public function validatedUsersCount(): int
    {
        return $this->validations
            ->filter(fn (QualityControlResultValidation $validation): bool => $validation->decision === QualityResultDecision::Validated)
            ->pluck('validated_by')
            ->filter()
            ->unique()
            ->count();
    }

    public function isFullyValidated(): bool
    {
        if ($this->isRejected()) {
            return false;
        }

        return $this->validatedUsersCount() >= $this->point->requiredValidations();
    }

    public function displayValue(): string
    {
        return match ($this->point->result_type->value) {
            'conformity' => $this->value_boolean ? 'Conforme' : 'Non conforme',
            'numeric' => trim(($this->value_numeric ?? '').' '.($this->unit_snapshot ?? '')),
            'text' => (string) ($this->value_text ?? ''),
            'choice' => (string) ($this->value_choice ?? ''),
            default => '—',
        };
    }
}
