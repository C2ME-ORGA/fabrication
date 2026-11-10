<?php

namespace App\Services;

use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlStatus;
use App\Enums\QualityControlType;
use App\Enums\QualityDeviationScope;
use App\Enums\QualityDeviationStatus;
use App\Enums\QualityRequirementScope;
use App\Models\NonConformity;
use App\Models\QualityControlResult;
use App\Models\QualityDeviation;
use App\Models\QualityDeviationAttachment;
use App\Models\QualityDeviationTarget;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dérogations qualité sélectives.
 *
 * Une dérogation ne modifie jamais un résultat. Elle ne peut lever qu'une
 * cible précise et ne peut jamais couvrir un contrôle manquant, un contrôle
 * final documentaire ou une NC critique.
 */
class QualityDeviationService
{
    public function __construct(
        protected QualityReferenceGenerator $references,
        protected QualityEvidenceStorageService $evidence,
        protected QualityAuditService $audit,
        protected Gate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(WorkOrder $workOrder, array $data, User $actor): QualityDeviation
    {
        $validated = Validator::make($data, [
            'reason' => [
                'required',
                'string',
                'min:'.max(20, (int) config('quality.deviations.reason_min_length', 20)),
                'max:10000',
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'scope' => ['required', Rule::enum(QualityDeviationScope::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'work_order_quality_control_id' => ['nullable', 'integer'],
            'work_order_quality_control_point_id' => ['nullable', 'integer'],
            'non_conformity_id' => ['nullable', 'integer'],
        ])->validate();

        return DB::transaction(function () use ($workOrder, $validated, $actor): QualityDeviation {
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->find($workOrder->getKey());

            if ($lockedWorkOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            if (! $this->gate->forUser($actor)->allows('create', QualityDeviation::class)) {
                $this->fail('deviation', 'Vous n\'êtes pas autorisé à demander une dérogation qualité.');
            }

            if (! $lockedWorkOrder->allowsOperationExecution()) {
                $this->fail(
                    'work_order',
                    'Une dérogation ne peut être demandée que sur un OF libéré ou en exécution.',
                );
            }

            $scope = QualityDeviationScope::from($validated['scope']);
            $target = $this->resolveTarget($lockedWorkOrder, $scope, $validated);

            $this->assertTargetIsNotAlreadyCovered($lockedWorkOrder, $scope, $target);

            $deviation = new QualityDeviation;
            $deviation->forceFill([
                'reference' => $this->references->next('deviation', now()->year),
                'work_order_id' => $lockedWorkOrder->getKey(),
                'work_order_number' => $lockedWorkOrder->number,
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
                'reason' => trim((string) $validated['reason']),
                'description' => $validated['description'] ?? null,
                'scope' => $scope->value,
                'status' => QualityDeviationStatus::Pending->value,
                'is_critical' => $target['is_critical'],
                'expires_at' => $validated['expires_at'] ?? null,
            ])->save();

            $this->createTarget($deviation, $target);
            $deviation->refresh()->load('targets');

            $this->audit->record(
                QualityAuditEvent::DeviationRequested,
                QualityAuditSubject::Deviation,
                (int) $deviation->getKey(),
                $deviation->reference,
                $actor,
                $lockedWorkOrder,
                null,
                QualityDeviationStatus::Pending->value,
                'Demande de dérogation qualité.',
                $this->auditMetadata($deviation),
            );

            return $deviation;
        }, attempts: 3);
    }

    /**
     * Approuve une dérogation. Un point critique exige deux approbateurs
     * distincts.
     */
    public function approve(QualityDeviation $deviation, User $actor, ?string $comment = null): QualityDeviation
    {
        return DB::transaction(function () use ($deviation, $actor, $comment): QualityDeviation {
            $locked = $this->lock($deviation);

            if (! $this->gate->forUser($actor)->allows('approve', $locked)) {
                $this->fail('deviation', 'Vous n\'êtes pas autorisé à approuver cette dérogation.');
            }

            if ($locked->status !== QualityDeviationStatus::Pending) {
                $this->fail('status', 'Seule une dérogation en attente peut être approuvée.');
            }

            if ((int) $locked->requested_by === (int) $actor->getKey()) {
                $this->fail(
                    'approver',
                    'Le demandeur ne peut pas approuver sa propre demande de dérogation.',
                );
            }

            if ($locked->is_critical && $locked->granted_by !== null) {
                if ((int) $locked->granted_by === (int) $actor->getKey()) {
                    $this->fail(
                        'approver',
                        'La double approbation d\'une dérogation critique exige deux utilisateurs distincts.',
                    );
                }

                if ((int) $locked->requested_by === (int) $actor->getKey()) {
                    $this->fail(
                        'approver',
                        'Aucun approbateur d\'une dérogation critique ne peut être son demandeur.',
                    );
                }

                $locked->forceFill([
                    'second_approver_id' => $actor->getKey(),
                    'second_approved_at' => now(),
                    'status' => QualityDeviationStatus::Active->value,
                ])->save();

                $this->audit->record(
                    QualityAuditEvent::DeviationApproved,
                    QualityAuditSubject::Deviation,
                    (int) $locked->getKey(),
                    $locked->reference,
                    $actor,
                    $locked->workOrder,
                    QualityDeviationStatus::Pending->value,
                    QualityDeviationStatus::Active->value,
                    $comment,
                    $this->auditMetadata($locked) + ['rank' => 2],
                );

                return $this->activate($locked->refresh(), $actor, $comment);
            }

            $locked->forceFill([
                'granted_by' => $actor->getKey(),
                'granted_at' => now(),
                'status' => $locked->is_critical
                    ? QualityDeviationStatus::Pending->value
                    : QualityDeviationStatus::Active->value,
            ])->save();

            $this->audit->record(
                QualityAuditEvent::DeviationApproved,
                QualityAuditSubject::Deviation,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                QualityDeviationStatus::Pending->value,
                $locked->status->value,
                $comment,
                $this->auditMetadata($locked) + ['rank' => 1],
            );

            if ($locked->status === QualityDeviationStatus::Active) {
                return $this->activate($locked->refresh(), $actor, $comment);
            }

            return $locked->refresh();
        }, attempts: 3);
    }

    public function reject(QualityDeviation $deviation, User $actor, string $reason): QualityDeviation
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason', 'Le motif de refus est obligatoire.');
        }

        return DB::transaction(function () use ($deviation, $actor, $reason): QualityDeviation {
            $locked = $this->lock($deviation);

            if (! $this->gate->forUser($actor)->allows('reject', $locked)) {
                $this->fail('deviation', 'Vous n\'êtes pas autorisé à refuser cette dérogation.');
            }

            if ($locked->status !== QualityDeviationStatus::Pending) {
                $this->fail('status', 'Seule une dérogation en attente peut être refusée.');
            }

            $locked->forceFill([
                'status' => QualityDeviationStatus::Rejected->value,
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->record(
                QualityAuditEvent::DeviationRejected,
                QualityAuditSubject::Deviation,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                QualityDeviationStatus::Pending->value,
                QualityDeviationStatus::Rejected->value,
                $reason,
                $this->auditMetadata($locked),
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function revoke(QualityDeviation $deviation, User $actor, string $reason): QualityDeviation
    {
        $reason = trim($reason);
        $minimum = max(20, (int) config('quality.deviations.reason_min_length', 20));

        if (mb_strlen($reason) < $minimum) {
            $this->fail('revoke_reason', "Le motif de révocation doit contenir au moins {$minimum} caractères.");
        }

        return DB::transaction(function () use ($deviation, $actor, $reason): QualityDeviation {
            $locked = $this->lock($deviation);

            if (! $this->gate->forUser($actor)->allows('revoke', $locked)) {
                $this->fail('deviation', 'Vous n\'êtes pas autorisé à révoquer cette dérogation.');
            }

            if ($locked->status !== QualityDeviationStatus::Active) {
                $this->fail('status', 'Seule une dérogation active peut être révoquée.');
            }

            if ($locked->isExpiredByDate()) {
                $this->fail('status', 'Cette dérogation est expirée et ne peut plus être révoquée.');
            }

            $locked->forceFill([
                'status' => QualityDeviationStatus::Revoked->value,
                'revoked_by' => $actor->getKey(),
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            $this->audit->record(
                QualityAuditEvent::DeviationRevoked,
                QualityAuditSubject::Deviation,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                QualityDeviationStatus::Active->value,
                QualityDeviationStatus::Revoked->value,
                $reason,
                $this->auditMetadata($locked),
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function expireOverdueForWorkOrder(WorkOrder $workOrder, ?User $actor = null): void
    {
        DB::transaction(function () use ($workOrder, $actor): void {
            $deviations = QualityDeviation::query()
                ->where('work_order_id', $workOrder->getKey())
                ->where('status', QualityDeviationStatus::Active->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($deviations as $deviation) {
                $deviation->forceFill(['status' => QualityDeviationStatus::Expired->value])->save();

                $this->audit->record(
                    QualityAuditEvent::DeviationExpired,
                    QualityAuditSubject::Deviation,
                    (int) $deviation->getKey(),
                    $deviation->reference,
                    $actor,
                    $deviation->workOrder,
                    QualityDeviationStatus::Active->value,
                    QualityDeviationStatus::Expired->value,
                    'Dérogation arrivée à expiration.',
                    $this->auditMetadata($deviation),
                );
            }
        }, attempts: 3);
    }

    public function attachEvidence(
        QualityDeviation $deviation,
        UploadedFile $file,
        User $actor,
    ): QualityDeviationAttachment {
        return DB::transaction(function () use ($deviation, $file, $actor): QualityDeviationAttachment {
            $locked = $this->lock($deviation);

            if (! $this->gate->forUser($actor)->allows('attachEvidence', $locked)) {
                $this->fail('deviation', 'Vous n\'êtes pas autorisé à joindre une preuve à cette dérogation.');
            }

            $stored = $this->evidence->storeForDeviation($file, $locked);

            try {
                $attachment = new QualityDeviationAttachment;
                $attachment->forceFill([
                    'quality_deviation_id' => $locked->getKey(),
                    'work_order_id' => $locked->work_order_id,
                    ...$stored,
                    'uploaded_by' => $actor->getKey(),
                    'created_at' => now(),
                ])->save();
            } catch (Throwable $exception) {
                $this->evidence->delete($stored['disk'], $stored['path']);

                throw $exception;
            }

            $this->audit->record(
                QualityAuditEvent::DeviationEvidenceAdded,
                QualityAuditSubject::Deviation,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                $locked->status->value,
                $locked->status->value,
                'Ajout d\'une pièce justificative à la dérogation.',
                [
                    'attachment_id' => $attachment->getKey(),
                    'original_filename' => $attachment->original_filename,
                    'sha256' => $attachment->sha256,
                ],
            );

            return $attachment->refresh();
        }, attempts: 3);
    }

    protected function activate(QualityDeviation $deviation, User $actor, ?string $comment): QualityDeviation
    {
        $this->audit->record(
            QualityAuditEvent::DeviationGranted,
            QualityAuditSubject::Deviation,
            (int) $deviation->getKey(),
            $deviation->reference,
            $actor,
            $deviation->workOrder,
            QualityDeviationStatus::Pending->value,
            QualityDeviationStatus::Active->value,
            $comment,
            $this->auditMetadata($deviation),
        );

        return $deviation->refresh();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{work_order_quality_control_id: ?int, work_order_quality_control_point_id: ?int, non_conformity_id: ?int, is_critical: bool, label: string}
     */
    protected function resolveTarget(
        WorkOrder $workOrder,
        QualityDeviationScope $scope,
        array $validated,
    ): array {
        $provided = array_filter([
            'work_order_quality_control_id' => $validated['work_order_quality_control_id'] ?? null,
            'work_order_quality_control_point_id' => $validated['work_order_quality_control_point_id'] ?? null,
            'non_conformity_id' => $validated['non_conformity_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if (count($provided) !== 1) {
            $this->fail('target', 'Une dérogation doit cibler exactement un contrôle, un point ou une non-conformité.');
        }

        return match ($scope) {
            QualityDeviationScope::Control => $this->resolveControlTarget($workOrder, (int) ($validated['work_order_quality_control_id'] ?? 0)),
            QualityDeviationScope::ControlPoint => $this->resolvePointTarget($workOrder, (int) ($validated['work_order_quality_control_point_id'] ?? 0)),
            QualityDeviationScope::NonConformity => $this->resolveNonConformityTarget($workOrder, (int) ($validated['non_conformity_id'] ?? 0)),
            QualityDeviationScope::WorkOrder => $this->fail(
                'scope',
                'Une dérogation globale au niveau de l\'OF est interdite : ciblez un contrôle, un point ou une NC.',
            ),
        };
    }

    /**
     * @return array{work_order_quality_control_id: int, work_order_quality_control_point_id: null, non_conformity_id: null, is_critical: bool, label: string}
     */
    protected function resolveControlTarget(WorkOrder $workOrder, int $controlId): array
    {
        $control = WorkOrderQualityControl::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereKey($controlId)
            ->lockForUpdate()
            ->first();

        if ($control === null) {
            $this->fail('work_order_quality_control_id', 'Le contrôle sélectionné n\'appartient pas à cet OF.');
        }

        $this->assertControlCanBeDeviated($control);

        return [
            'work_order_quality_control_id' => (int) $control->getKey(),
            'work_order_quality_control_point_id' => null,
            'non_conformity_id' => null,
            'is_critical' => $control->points()->where('is_critical', true)->exists(),
            'label' => $control->template_code.' — '.$control->template_name,
        ];
    }

    /**
     * @return array{work_order_quality_control_id: null, work_order_quality_control_point_id: int, non_conformity_id: null, is_critical: bool, label: string}
     */
    protected function resolvePointTarget(WorkOrder $workOrder, int $pointId): array
    {
        $point = WorkOrderQualityControlPoint::query()
            ->whereKey($pointId)
            ->lockForUpdate()
            ->first();

        if ($point === null) {
            $this->fail('work_order_quality_control_point_id', 'Le point de contrôle sélectionné est introuvable.');
        }

        $control = WorkOrderQualityControl::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereKey($point->work_order_quality_control_id)
            ->first();

        if ($control === null) {
            $this->fail('work_order_quality_control_point_id', 'Le point sélectionné n\'appartient pas à cet OF.');
        }

        if ($control->control_type === QualityControlType::Final
            && $control->scope === QualityRequirementScope::WorkOrder) {
            $this->fail('target', 'Un contrôle final documentaire ne peut jamais faire l\'objet d\'une dérogation.');
        }

        if (! $point->allow_deviation) {
            $this->fail('target', 'Ce point de contrôle n\'autorise pas la dérogation.');
        }

        $nonConformingResults = $point->results()
            ->where('is_conform', false)
            ->get()
            ->filter(fn (QualityControlResult $result): bool => ! $result->fresh()->isRejected());

        if ($nonConformingResults->isEmpty()) {
            $this->fail('target', 'Une dérogation ne peut cibler un point sans résultat non conforme enregistré.');
        }

        return [
            'work_order_quality_control_id' => null,
            'work_order_quality_control_point_id' => (int) $point->getKey(),
            'non_conformity_id' => null,
            'is_critical' => (bool) $point->is_critical,
            'label' => 'Point « '.$point->label.' »',
        ];
    }

    /**
     * @return array{work_order_quality_control_id: null, work_order_quality_control_point_id: null, non_conformity_id: int, is_critical: false, label: string}
     */
    protected function resolveNonConformityTarget(WorkOrder $workOrder, int $nonConformityId): array
    {
        $nonConformity = NonConformity::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereKey($nonConformityId)
            ->lockForUpdate()
            ->first();

        if ($nonConformity === null) {
            $this->fail('non_conformity_id', 'La non-conformité sélectionnée n\'appartient pas à cet OF.');
        }

        if ($nonConformity->severity === NonConformitySeverity::Critical) {
            $this->fail('non_conformity_id', 'Une non-conformité critique ne peut jamais faire l\'objet d\'une dérogation.');
        }

        if (in_array($nonConformity->status, [
            NonConformityStatus::Closed,
            NonConformityStatus::Cancelled,
        ], true)) {
            $this->fail('non_conformity_id', 'Cette non-conformité est déjà clôturée ou annulée.');
        }

        return [
            'work_order_quality_control_id' => null,
            'work_order_quality_control_point_id' => null,
            'non_conformity_id' => (int) $nonConformity->getKey(),
            'is_critical' => false,
            'label' => 'Non-conformité '.$nonConformity->reference,
        ];
    }

    protected function assertControlCanBeDeviated(WorkOrderQualityControl $control): void
    {
        if ($control->status === QualityControlStatus::Cancelled) {
            $this->fail('target', 'Un contrôle annulé ne peut pas faire l\'objet d\'une dérogation.');
        }

        if ($control->control_type === QualityControlType::Final
            && $control->scope === QualityRequirementScope::WorkOrder) {
            $this->fail('target', 'Un contrôle final documentaire ne peut jamais faire l\'objet d\'une dérogation.');
        }

        $pointsWithNonConformingResult = $control->points()
            ->get()
            ->filter(function (WorkOrderQualityControlPoint $point): bool {
                return $point->results()
                    ->where('is_conform', false)
                    ->get()
                    ->contains(fn (QualityControlResult $result): bool => ! $result->fresh()->isRejected());
            });

        if ($pointsWithNonConformingResult->isEmpty()) {
            $this->fail('target', 'Une dérogation ne peut cibler un contrôle sans résultat non conforme enregistré.');
        }

        if ($pointsWithNonConformingResult->contains(fn (WorkOrderQualityControlPoint $point): bool => ! $point->allow_deviation)) {
            $this->fail(
                'target',
                'Tous les points non conformes du contrôle doivent autoriser explicitement la dérogation.',
            );
        }
    }

    /**
     * @param  array{work_order_quality_control_id: ?int, work_order_quality_control_point_id: ?int, non_conformity_id: ?int, is_critical: bool, label: string}  $target
     */
    protected function createTarget(QualityDeviation $deviation, array $target): void
    {
        $model = new QualityDeviationTarget;
        $model->forceFill([
            'quality_deviation_id' => $deviation->getKey(),
            'work_order_quality_control_id' => $target['work_order_quality_control_id'],
            'work_order_quality_control_point_id' => $target['work_order_quality_control_point_id'],
            'non_conformity_id' => $target['non_conformity_id'],
        ])->save();
    }

    /**
     * @param  array{work_order_quality_control_id: ?int, work_order_quality_control_point_id: ?int, non_conformity_id: ?int, is_critical: bool, label: string}  $target
     */
    protected function assertTargetIsNotAlreadyCovered(
        WorkOrder $workOrder,
        QualityDeviationScope $scope,
        array $target,
    ): void {
        $query = QualityDeviation::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereIn('status', [
                QualityDeviationStatus::Pending->value,
                QualityDeviationStatus::Active->value,
            ]);

        $query->whereHas('targets', function ($query) use ($scope, $target): void {
            match ($scope) {
                QualityDeviationScope::Control => $query->where(
                    'work_order_quality_control_id',
                    $target['work_order_quality_control_id'],
                ),
                QualityDeviationScope::ControlPoint => $query->where(
                    'work_order_quality_control_point_id',
                    $target['work_order_quality_control_point_id'],
                ),
                QualityDeviationScope::NonConformity => $query->where(
                    'non_conformity_id',
                    $target['non_conformity_id'],
                ),
                default => $query->whereRaw('1 = 0'),
            };
        });

        if ($query->lockForUpdate()->first() !== null) {
            $this->fail(
                'target',
                'Une dérogation en attente ou active couvre déjà exactement cette cible.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditMetadata(QualityDeviation $deviation): array
    {
        $deviation->loadMissing('targets');

        return [
            'reference' => $deviation->reference,
            'scope' => $deviation->scope->value,
            'status' => $deviation->status->value,
            'is_critical' => $deviation->is_critical,
            'reason' => $deviation->reason,
            'expires_at' => $deviation->expires_at?->toIso8601String(),
            'requested_by' => $deviation->requested_by,
            'requested_at' => $deviation->requested_at?->toIso8601String(),
            'granted_by' => $deviation->granted_by,
            'granted_at' => $deviation->granted_at?->toIso8601String(),
            'second_approver_id' => $deviation->second_approver_id,
            'targets' => $deviation->targets->map(fn (QualityDeviationTarget $target): array => [
                'control_id' => $target->work_order_quality_control_id,
                'control_point_id' => $target->work_order_quality_control_point_id,
                'non_conformity_id' => $target->non_conformity_id,
                'description' => $target->describe(),
            ])->all(),
        ];
    }

    protected function lock(QualityDeviation $deviation): QualityDeviation
    {
        $locked = QualityDeviation::query()->lockForUpdate()->find($deviation->getKey());

        if ($locked === null) {
            $this->fail('deviation', 'Cette dérogation n\'existe plus.');
        }

        return $locked;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
