<?php

namespace App\Models;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use Database\Factories\QualityAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Journal d'audit immuable du domaine qualité.
 *
 * Les entrées ne sont jamais mises à jour ni supprimées. La protection est
 * technique : toute tentative via Eloquent lève une exception. Elle ne
 * protège pas contre un accès SQL direct disposant des privilèges suffisants.
 *
 * subject_type / subject_id forment une référence typée explicite. Le projet
 * n'utilise pas de relation polymorphe ; subject_reference conserve une
 * valeur lisible même si l'entité visée est supprimée.
 */
#[Fillable([
    'work_order_id',
    'work_order_number',
    'subject_type',
    'subject_id',
    'subject_reference',
    'user_id',
    'event',
    'from_status',
    'to_status',
    'comment',
    'metadata',
])]
class QualityAuditLog extends Model
{
    /** @use HasFactory<QualityAuditLogFactory> */
    use HasFactory;

    /**
     * Journal immuable : aucune colonne updated_at n'est maintenue.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => QualityAuditSubject::class,
            'event' => QualityAuditEvent::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @throws RuntimeException
     */
    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'audit qualité sont immuables et ne peuvent pas être modifiées.'
        );
    }

    /**
     * @throws RuntimeException
     */
    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'audit qualité sont immuables et ne peuvent pas être supprimées.'
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
