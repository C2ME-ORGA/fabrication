<?php

namespace App\Models;

use App\Enums\OperationTimeEntryType;
use Database\Factories\OperationTimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de temps d'un opérateur sur une opération d'un OF.
 *
 * Les dates sont stockées en UTC. Une session active est une session dont
 * `ended_at` est nul ; l'index unique sur `active_user_id` garantit qu'un
 * opérateur ne peut avoir qu'une seule session active, tous OF confondus.
 */
#[Fillable([
    'work_order_operation_id',
    'original_entry_id',
    'user_id',
    'started_at',
    'ended_at',
    'duration_seconds',
    'entry_type',
    'created_by',
    'corrected_by',
    'correction_reason',
    'notes',
])]
class OperationTimeEntry extends Model
{
    /** @use HasFactory<OperationTimeEntryFactory> */
    use HasFactory;

    /**
     * Colonne générée par la base : jamais écrite par l'application.
     */
    public const GENERATED_FIELDS = ['active_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'entry_type' => OperationTimeEntryType::class,
        ];
    }

    /**
     * @return BelongsTo<WorkOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperation::class, 'work_order_operation_id');
    }

    /**
     * Opérateur dont le temps est enregistré.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Auteur de la saisie (peut différer de l'opérateur en saisie manuelle).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Auteur de la dernière correction.
     *
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * Entrée d'origine d'une correction.
     *
     * @return BelongsTo<self, $this>
     */
    public function originalEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_entry_id');
    }

    /**
     * Corrections append-only rattachées à cette entrée.
     *
     * @return HasMany<self, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'original_entry_id')->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function scopeClosed(Builder $query): void
    {
        $query->whereNotNull('ended_at');
    }
}
