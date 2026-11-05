<?php

namespace App\Models;

use Database\Factories\WorkOrderAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Journal d'audit immuable des actions effectuées sur un ordre de fabrication.
 *
 * Une entrée est écrite pour la création de l'OF et pour chaque transition
 * d'état. Les entrées ne sont jamais mises à jour ni supprimées.
 *
 * La protection est technique (au-delà d'une simple convention) :
 * toute tentative de mise à jour ou de suppression via Eloquent lève une
 * exception. Cela ne protège pas contre un accès SQL direct avec des
 * privilèges suffisants.
 */
#[Fillable([
    'work_order_id',
    'work_order_number',
    'user_id',
    'event',
    'from_status',
    'to_status',
    'comment',
    'metadata',
])]
class WorkOrderAuditLog extends Model
{
    /** @use HasFactory<WorkOrderAuditLogFactory> */
    use HasFactory;

    /**
     * Journal immuable : aucune colonne updated_at n'est maintenue.
     */
    public $timestamps = false;

    /**
     * Empêche la modification d'une entrée d'audit existante.
     *
     * @throws RuntimeException
     */
    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'audit des ordres de fabrication sont immuables et ne peuvent pas être modifiées.'
        );
    }

    /**
     * Empêche la suppression d'une entrée d'audit existante.
     *
     * @throws RuntimeException
     */
    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'audit des ordres de fabrication sont immuables et ne peuvent pas être supprimées.'
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'metadata' => 'array',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
