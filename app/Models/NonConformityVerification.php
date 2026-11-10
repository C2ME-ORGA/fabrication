<?php

namespace App\Models;

use App\Enums\NonConformityVerificationResult;
use App\Enums\NonConformityVerificationType;
use Database\Factories\NonConformityVerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Vérification append-only d'une non-conformité.
 */
#[Fillable([])]
class NonConformityVerification extends Model
{
    /** @use HasFactory<NonConformityVerificationFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NonConformityVerificationType::class,
            'result' => NonConformityVerificationResult::class,
            'performed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Une vérification de non-conformité est immuable et ne peut pas être modifiée.'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Une vérification de non-conformité est immuable et ne peut pas être supprimée.'
        );
    }

    /**
     * @return BelongsTo<NonConformity, $this>
     */
    public function nonConformity(): BelongsTo
    {
        return $this->belongsTo(NonConformity::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * @return BelongsTo<WorkOrderQualityControl, $this>
     */
    public function counterControl(): BelongsTo
    {
        return $this->belongsTo(WorkOrderQualityControl::class, 'counter_control_id');
    }
}
