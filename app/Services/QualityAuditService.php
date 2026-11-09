<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Models\QualityAuditLog;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;

/**
 * Écriture du journal d'audit du domaine qualité.
 *
 * Une entrée est toujours écrite dans la transaction métier de l'appelant :
 * si l'action échoue et rollback, l'audit est annulé avec elle. Le journal est
 * append-only (modèle QualityAuditLog).
 */
class QualityAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        QualityAuditEvent $event,
        QualityAuditSubject $subjectType,
        ?int $subjectId = null,
        ?string $subjectReference = null,
        ?User $actor = null,
        ?WorkOrder $workOrder = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        array $metadata = [],
    ): QualityAuditLog {
        return QualityAuditLog::query()->create([
            'work_order_id' => $workOrder?->getKey(),
            'work_order_number' => $workOrder?->number,
            'subject_type' => $subjectType->value,
            'subject_id' => $subjectId,
            'subject_reference' => $subjectReference,
            'user_id' => $actor?->getKey() ?? Auth::id(),
            'event' => $event->value,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    public function templateReference(QualityControlTemplate $template): string
    {
        return (string) $template->code;
    }

    public function versionReference(
        QualityControlTemplate $template,
        QualityControlTemplateVersion $version,
    ): string {
        return $template->code.'/v'.$version->version_number;
    }
}
