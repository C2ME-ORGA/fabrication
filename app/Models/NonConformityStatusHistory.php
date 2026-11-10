<?php

namespace App\Models;

use App\Enums\NonConformityStatus;
use Database\Factories\NonConformityStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Historique append-only des changements d'état d'une non-conformité.
 */
#[Fillable([])]
class NonConformityStatusHistory extends Model
{
    /** @use HasFactory<NonConformityStatusHistoryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => NonConformityStatus::class,
            'to_status' => NonConformityStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'L\'historique des non-conformités est immuable et ne peut pas être modifié.'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'L\'historique des non-conformités est immuable et ne peut pas être supprimé.'
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
