<?php

namespace App\Models;

use App\Enums\CorrectiveActionStatus;
use Database\Factories\NonConformityCorrectiveActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Action corrective d'une non-conformité.
 */
#[Fillable([])]
class NonConformityCorrectiveAction extends Model
{
    /** @use HasFactory<NonConformityCorrectiveActionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_date' => 'date',
            'status' => CorrectiveActionStatus::class,
            'completed_at' => 'datetime',
            'verification_required' => 'boolean',
        ];
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
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
