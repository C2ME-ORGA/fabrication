<?php

namespace App\Models;

use App\Enums\WorkshopOperationAuditEvent;
use Database\Factories\WorkOrderOperationAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Journal d'audit immuable des opérations d'un ordre de fabrication.
 *
 * Toute action sensible (création, modification, transition, chronométrage,
 * saisie manuelle, correction) écrit une entrée dans la même transaction que
 * la mutation métier. Une entrée ne peut jamais être modifiée ni supprimée
 * via Eloquent.
 */
#[Fillable([
    'work_order_operation_id',
    'work_order_id',
    'work_order_number',
    'operation_code',
    'user_id',
    'event',
    'from_status',
    'to_status',
    'comment',
    'metadata',
])]
class WorkOrderOperationAuditLog extends Model
{
    /** @use HasFactory<WorkOrderOperationAuditLogFactory> */
    use HasFactory;

    /**
     * Journal immuable : aucune colonne updated_at n'est maintenue.
     */
    public $timestamps = false;

    /**
     * @throws RuntimeException
     */
    protected function performUpdate(Builder $query): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'audit des opérations sont immuables et ne peuvent pas être modifiées.'
        );
    }

    /**
     * @throws RuntimeException
     */
    protected function performDeleteOnModel(): void
    {
        throw new RuntimeException(
            'Les entrées du journal d\'audit des opérations sont immuables et ne peuvent pas être supprimées.'
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'event' => WorkshopOperationAuditEvent::class,
            'metadata' => 'array',
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
