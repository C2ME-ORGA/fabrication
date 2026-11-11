<?php

namespace App\Models;

use App\Enums\QualityReleasePhase;
use App\Enums\QualityReleaseReason;
use App\Policies\WorkOrderQualityReleasePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Preuve persistée de libération qualité d'un ordre de fabrication.
 *
 * La preuve est append-only : une nouvelle libération ajoute une ligne et
 * n'écrase jamais l'historique. Elle contient l'instantané de l'éligibilité
 * FC-7 afin de rester compréhensible après évolution des règles.
 */
#[Fillable([
    'work_order_id',
    'released_by',
    'released_at',
    'phase',
    'eligible',
    'requirements_present',
    'eligibility_snapshot',
    'snapshot_hash',
    'rules_version',
    'reason',
    'comment',
    'created_at',
])]
#[UsePolicy(WorkOrderQualityReleasePolicy::class)]
class WorkOrderQualityRelease extends Model
{
    /**
     * Preuve append-only : aucune colonne updated_at n'est maintenue.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => QualityReleasePhase::class,
            'reason' => QualityReleaseReason::class,
            'eligible' => 'boolean',
            'requirements_present' => 'boolean',
            'eligibility_snapshot' => 'array',
            'released_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Une preuve de libération qualité est immuable et ne peut pas être modifiée.',
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Une preuve de libération qualité est immuable et ne peut pas être supprimée.',
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
     * @return BelongsTo<User, $this>
     */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function reference(): string
    {
        return 'QR-'.$this->getKey();
    }
}
