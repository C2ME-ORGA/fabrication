<?php

namespace App\Models;

use App\Policies\QualityControlResultAttachmentPolicy;
use Database\Factories\QualityControlResultAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preuve jointe à un résultat de contrôle (photo, PDF, justificatif).
 *
 * Le chemin est généré côté serveur et le SHA-256 permet de détecter toute
 * substitution ultérieure du fichier.
 */
#[Fillable([])]
#[UsePolicy(QualityControlResultAttachmentPolicy::class)]
class QualityControlResultAttachment extends Model
{
    /** @use HasFactory<QualityControlResultAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QualityControlResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(QualityControlResult::class, 'quality_control_result_id');
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
