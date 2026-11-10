<?php

namespace App\Models;

use App\Enums\QualityDeviationScope;
use App\Enums\QualityDeviationStatus;
use Database\Factories\QualityDeviationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Dérogation qualité sélective et immuable hors décision/révocation.
 */
#[Fillable([])]
class QualityDeviation extends Model
{
    /** @use HasFactory<QualityDeviationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => QualityDeviationScope::class,
            'status' => QualityDeviationStatus::class,
            'is_critical' => 'boolean',
            'requested_at' => 'datetime',
            'granted_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return HasMany<QualityDeviationTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(QualityDeviationTarget::class);
    }

    /**
     * @return HasMany<QualityDeviationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(QualityDeviationAttachment::class)->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === QualityDeviationStatus::Pending;
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Une dérogation qualité ne peut pas être supprimée : utilisez la révocation.'
        );
    }

    public function isActive(): bool
    {
        return $this->status === QualityDeviationStatus::Active
            && ! $this->isExpiredByDate();
    }

    public function isExpiredByDate(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function targetDescription(): string
    {
        $target = $this->targets->first();

        if ($target === null) {
            return '—';
        }

        return $target->describe();
    }
}
