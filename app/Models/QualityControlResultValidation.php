<?php

namespace App\Models;

use App\Enums\QualityResultDecision;
use Database\Factories\QualityControlResultValidationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Décision de validation append-only d'un résultat de contrôle.
 */
#[Fillable([])]
class QualityControlResultValidation extends Model
{
    /** @use HasFactory<QualityControlResultValidationFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => QualityResultDecision::class,
            'validated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Une validation de résultat qualité est immuable et ne peut pas être modifiée.'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Une validation de résultat qualité est immuable et ne peut pas être supprimée.'
        );
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
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
