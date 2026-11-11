<?php

namespace App\Services;

use App\DTO\ClosureEligibility;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityReleasePhase;
use App\Enums\QualityReleaseReason;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Écriture et lecture des preuves de libération qualité.
 *
 * Aucune règle d'éligibilité n'est dupliquée : le service consomme le
 * résultat de QualityClosureEligibilityService. Une preuve est toujours
 * ajoutée, jamais mise à jour ni supprimée.
 */
class WorkOrderQualityReleaseService
{
    public function __construct(
        protected QualityClosureEligibilityService $eligibility,
        protected QualityAuditService $qualityAudit,
        protected WorkOrderAuditService $workOrderAudit,
    ) {}

    /**
     * Preuve écrite dans la transaction de clôture.
     *
     * L'éligibilité est recalculée ici, sous verrou de l'OF : aucun appelant
     * ne peut injecter un résultat positif fabriqué. La preuve n'est créée
     * que pour un OF en contrôle qualité, c'est-à-dire avant le passage à
     * `completed`.
     */
    public function recordForClosure(WorkOrder $workOrder, ?User $actor = null): WorkOrderQualityRelease
    {
        $this->authorize($actor, $workOrder);

        return DB::transaction(function () use ($workOrder, $actor): WorkOrderQualityRelease {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());

            if ($locked->status !== WorkOrderStatus::QualityCheck) {
                throw ValidationException::withMessages([
                    'quality' => 'Une preuve de clôture ne peut être enregistrée que pour un OF en contrôle qualité.',
                ]);
            }

            $eligibility = $this->eligibility->evaluate(
                $locked,
                QualityClosureEligibilityService::PHASE_CLOSURE,
            );

            return $this->persist(
                $locked,
                $actor,
                QualityReleasePhase::Closure,
                QualityReleaseReason::Completion,
                null,
                $eligibility,
            );
        }, attempts: 3);
    }

    /**
     * Re-libération explicite d'un OF historique terminé.
     *
     * L'évaluation FC-7 est forcée hors du court-circuit `isLocked()` afin de
     * produire une preuve réelle, jamais une reconstruction depuis l'audit.
     */
    public function recordHistoricalReview(
        WorkOrder $workOrder,
        ?User $actor,
        string $comment,
    ): WorkOrderQualityRelease {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Un motif est obligatoire pour une revue historique.',
            ]);
        }

        $this->authorize($actor, $workOrder);

        return DB::transaction(function () use ($workOrder, $actor, $comment): WorkOrderQualityRelease {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());

            if ($locked->status !== WorkOrderStatus::Completed) {
                throw ValidationException::withMessages([
                    'quality' => 'Une revue historique ne peut être enregistrée que pour un OF terminé.',
                ]);
            }

            $eligibility = $this->eligibility->evaluate(
                $locked,
                QualityClosureEligibilityService::PHASE_HISTORICAL_REVIEW,
                ignoreLocked: true,
            );

            return $this->persist(
                $locked,
                $actor,
                QualityReleasePhase::HistoricalReview,
                QualityReleaseReason::HistoricalReview,
                $comment,
                $eligibility,
            );
        }, attempts: 3);
    }

    public function latestFor(WorkOrder $workOrder): ?WorkOrderQualityRelease
    {
        return WorkOrderQualityRelease::query()
            ->where('work_order_id', $workOrder->getKey())
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Hash déterministe d'un instantané d'éligibilité.
     *
     * Les objets associatifs sont triés récursivement par clé ; l'ordre des
     * tableaux indexés est conservé. MySQL réordonne les clés JSON lors du
     * stockage, donc le hash doit être calculé sur une représentation
     * canonique et non sur l'ordre PHP d'origine.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function hashSnapshot(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode(
                self::canonicalize($snapshot),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * Vérifie qu'une preuve persistée correspond toujours à son empreinte.
     */
    public function verifySnapshot(WorkOrderQualityRelease $release): bool
    {
        $snapshot = $release->eligibility_snapshot;

        if (! is_array($snapshot)) {
            return false;
        }

        return hash_equals($release->snapshot_hash, self::hashSnapshot($snapshot));
    }

    protected static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => self::canonicalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        return array_map(
            fn (mixed $item): mixed => self::canonicalize($item),
            $value,
        );
    }

    protected function persist(
        WorkOrder $workOrder,
        ?User $actor,
        QualityReleasePhase $phase,
        QualityReleaseReason $reason,
        ?string $comment,
        ClosureEligibility $eligibility,
    ): WorkOrderQualityRelease {
        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'quality' => $eligibility->message(),
            ]);
        }

        $snapshot = $eligibility->toArray();
        $snapshotHash = self::hashSnapshot($snapshot);

        $requirementsPresent = $this->eligibility->hasMandatoryRequirements($workOrder);
        $rulesVersion = (string) config('quality.rules_version', '1');

        $release = WorkOrderQualityRelease::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'released_by' => $actor?->getKey(),
            'released_at' => now(),
            'phase' => $phase,
            'eligible' => true,
            'requirements_present' => $requirementsPresent,
            'eligibility_snapshot' => $snapshot,
            'snapshot_hash' => $snapshotHash,
            'rules_version' => $rulesVersion,
            'reason' => $reason,
            'comment' => $comment,
            'created_at' => now(),
        ]);

        $this->qualityAudit->record(
            QualityAuditEvent::QualityReleaseRecorded,
            QualityAuditSubject::WorkOrder,
            (int) $workOrder->getKey(),
            $workOrder->number,
            $actor,
            $workOrder,
            null,
            $phase->value,
            $comment,
            [
                'release_id' => (int) $release->getKey(),
                'eligible' => true,
                'requirements_present' => $requirementsPresent,
                'snapshot_hash' => $snapshotHash,
                'reason' => $reason->value,
                'rules_version' => $rulesVersion,
            ],
        );

        $this->workOrderAudit->record(
            $workOrder,
            WorkOrderAuditEvent::QualityReleased,
            $actor,
            null,
            $phase->value,
            $comment,
            [
                'quality_release_id' => (int) $release->getKey(),
                'snapshot_hash' => $snapshotHash,
                'reason' => $reason->value,
                'requirements_present' => $requirementsPresent,
            ],
        );

        return $release->refresh();
    }

    protected function authorize(?User $actor, WorkOrder $workOrder): void
    {
        if ($actor === null || ! $actor->exists) {
            throw ValidationException::withMessages([
                'quality' => 'Un utilisateur autorisé et enregistré est requis pour libérer la qualité.',
            ]);
        }

        Gate::forUser($actor)->authorize('releaseQuality', $workOrder);
    }
}
