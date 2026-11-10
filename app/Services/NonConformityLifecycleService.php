<?php

namespace App\Services;

use App\Enums\NonConformityStatus;
use App\Enums\NonConformityVerificationResult;
use App\Enums\NonConformityVerificationType;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Models\NonConformity;
use App\Models\NonConformityVerification;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transitions du cycle de vie des non-conformités.
 */
class NonConformityLifecycleService
{
    public function __construct(
        protected NonConformityService $nonConformities,
        protected QualityAuditService $audit,
        protected Gate $gate,
    ) {}

    public function start(NonConformity $nonConformity, User $actor, ?string $comment = null): NonConformity
    {
        return DB::transaction(function () use ($nonConformity, $actor, $comment): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('start', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à prendre en charge cette non-conformité.');
            }

            if ($locked->status !== NonConformityStatus::Open) {
                $this->fail('status', 'Seule une non-conformité ouverte peut être prise en charge.');
            }

            $locked->forceFill([
                'status' => NonConformityStatus::InProgress->value,
                'taken_in_charge_at' => $locked->taken_in_charge_at ?? now(),
            ])->save();

            $this->nonConformities->recordHistory(
                $locked,
                NonConformityStatus::Open,
                NonConformityStatus::InProgress,
                $actor,
                $comment,
                ['taken_in_charge_at' => $locked->taken_in_charge_at?->toIso8601String()],
            );

            $this->auditTransition($locked, NonConformityStatus::Open, NonConformityStatus::InProgress, $actor, $comment);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function submitForVerification(
        NonConformity $nonConformity,
        User $actor,
        ?string $comment = null,
    ): NonConformity {
        return DB::transaction(function () use ($nonConformity, $actor, $comment): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('submitForVerification', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à soumettre cette non-conformité à vérification.');
            }

            if ($locked->status !== NonConformityStatus::InProgress) {
                $this->fail('status', 'Seule une non-conformité en traitement peut être soumise à vérification.');
            }

            $actions = $locked->correctiveActions()->get();

            if ($actions->isEmpty() || ! $actions->contains(
                fn ($action): bool => in_array($action->status->value, ['done', 'verified'], true),
            )) {
                $this->fail(
                    'status',
                    'Au moins une action corrective terminée est nécessaire avant la vérification.',
                );
            }

            if ($actions->contains(fn ($action): bool => $action->status->isOpen())) {
                $this->fail(
                    'status',
                    'Toutes les actions correctives planifiées ou en cours doivent être terminées ou annulées.',
                );
            }

            $locked->forceFill([
                'status' => NonConformityStatus::PendingVerification->value,
                'treated_at' => now(),
            ])->save();

            $this->nonConformities->recordHistory(
                $locked,
                NonConformityStatus::InProgress,
                NonConformityStatus::PendingVerification,
                $actor,
                $comment,
                ['treated_at' => $locked->treated_at?->toIso8601String()],
            );

            $this->auditTransition(
                $locked,
                NonConformityStatus::InProgress,
                NonConformityStatus::PendingVerification,
                $actor,
                $comment,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function close(NonConformity $nonConformity, User $actor, string $comment): NonConformity
    {
        $comment = trim($comment);

        if ($comment === '') {
            $this->fail('closure_comment', 'Un commentaire de clôture est obligatoire.');
        }

        return DB::transaction(function () use ($nonConformity, $actor, $comment): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('close', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à clôturer cette non-conformité.');
            }

            if ($locked->status !== NonConformityStatus::PendingVerification) {
                $this->fail('status', 'Seule une non-conformité en attente de vérification peut être clôturée.');
            }

            if ($locked->correctiveActions()->get()->contains(fn ($action): bool => $action->status->isOpen())) {
                $this->fail('status', 'Toutes les actions correctives doivent être terminées ou annulées avant la clôture.');
            }

            $this->assertClosureRequirements($locked);

            $locked->forceFill([
                'status' => NonConformityStatus::Closed->value,
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'closure_comment' => $comment,
            ])->save();

            $this->nonConformities->recordHistory(
                $locked,
                NonConformityStatus::PendingVerification,
                NonConformityStatus::Closed,
                $actor,
                $comment,
                ['policy' => $locked->currentPolicy()],
            );

            $this->audit->record(
                QualityAuditEvent::NonConformityClosed,
                QualityAuditSubject::NonConformity,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                NonConformityStatus::PendingVerification->value,
                NonConformityStatus::Closed->value,
                $comment,
                ['policy' => $locked->currentPolicy()],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function reopen(NonConformity $nonConformity, User $actor, string $reason): NonConformity
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason', 'Le motif de réouverture est obligatoire.');
        }

        return DB::transaction(function () use ($nonConformity, $actor, $reason): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('reopen', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à réouvrir cette non-conformité.');
            }

            if (! $locked->isClosed()) {
                $this->fail('status', 'Seule une non-conformité clôturée peut être réouverte.');
            }

            $locked->forceFill([
                'status' => NonConformityStatus::InProgress->value,
                'closed_at' => null,
                'closed_by' => null,
                'closure_comment' => null,
                'treated_at' => null,
            ])->save();

            $this->nonConformities->recordHistory(
                $locked,
                NonConformityStatus::Closed,
                NonConformityStatus::InProgress,
                $actor,
                $reason,
            );

            $this->audit->record(
                QualityAuditEvent::NonConformityReopened,
                QualityAuditSubject::NonConformity,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                NonConformityStatus::Closed->value,
                NonConformityStatus::InProgress->value,
                $reason,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function cancel(NonConformity $nonConformity, User $actor, string $reason): NonConformity
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason', 'Le motif d\'annulation est obligatoire.');
        }

        return DB::transaction(function () use ($nonConformity, $actor, $reason): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('cancel', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à annuler cette non-conformité.');
            }

            if (! $locked->status->canTransitionTo(NonConformityStatus::Cancelled)) {
                $this->fail('status', 'Cette non-conformité ne peut pas être annulée depuis son statut actuel.');
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => NonConformityStatus::Cancelled->value,
                'unique_result_id' => null,
            ])->save();

            $this->nonConformities->recordHistory(
                $locked,
                $from,
                NonConformityStatus::Cancelled,
                $actor,
                $reason,
            );

            $this->auditTransition($locked, $from, NonConformityStatus::Cancelled, $actor, $reason);

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Retour en traitement après une vérification non conforme ou une action
     * corrective inefficace.
     */
    public function returnToTreatment(
        NonConformity $nonConformity,
        User $actor,
        string $reason,
    ): NonConformity {
        return DB::transaction(function () use ($nonConformity, $actor, $reason): NonConformity {
            $locked = $this->lock($nonConformity);

            if ($locked->status !== NonConformityStatus::PendingVerification) {
                return $locked->refresh();
            }

            $locked->forceFill([
                'status' => NonConformityStatus::InProgress->value,
                'treated_at' => null,
            ])->save();

            $this->nonConformities->recordHistory(
                $locked,
                NonConformityStatus::PendingVerification,
                NonConformityStatus::InProgress,
                $actor,
                $reason,
            );

            $this->auditTransition(
                $locked,
                NonConformityStatus::PendingVerification,
                NonConformityStatus::InProgress,
                $actor,
                $reason,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    protected function assertClosureRequirements(NonConformity $nonConformity): void
    {
        $snapshot = $nonConformity->currentPolicy();

        if (! (bool) ($snapshot['verification_required'] ?? false)) {
            return;
        }

        if ((bool) ($snapshot['counter_control_required'] ?? false)) {
            $this->assertConformingVerification($nonConformity, NonConformityVerificationType::CounterControl);
            $this->assertConformingVerification($nonConformity, NonConformityVerificationType::Effectiveness);

            return;
        }

        $closureReview = $nonConformity->latestVerification(NonConformityVerificationType::ClosureReview);
        $effectiveness = $nonConformity->latestVerification(NonConformityVerificationType::Effectiveness);

        $candidate = $this->latestById($closureReview, $effectiveness);

        if ($candidate === null || $candidate->result !== NonConformityVerificationResult::Conforming) {
            $this->fail(
                'verification',
                'Une vérification de clôture ou d\'efficacité conforme est nécessaire avant la clôture.',
            );
        }

        $this->assertVerifierIsSegregated($nonConformity, $candidate);
    }

    protected function assertConformingVerification(
        NonConformity $nonConformity,
        NonConformityVerificationType $type,
    ): void {
        $verification = $nonConformity->latestVerification($type);

        if ($verification === null || $verification->result !== NonConformityVerificationResult::Conforming) {
            $this->fail(
                'verification',
                "Une vérification conforme de type « {$type->getLabel()} » est nécessaire avant la clôture.",
            );
        }

        $this->assertVerifierIsSegregated($nonConformity, $verification);
    }

    protected function assertVerifierIsSegregated(
        NonConformity $nonConformity,
        NonConformityVerification $verification,
    ): void {
        if ($verification->performed_by === null) {
            $this->fail('verification', 'La vérification doit identifier son auteur.');
        }

        if ((int) $verification->performed_by === (int) $nonConformity->detected_by
            || (int) $verification->performed_by === (int) $nonConformity->created_by) {
            $this->fail(
                'verification',
                'La personne ayant déclaré la non-conformité ne peut pas réaliser sa vérification.',
            );
        }
    }

    protected function latestById(
        ?NonConformityVerification $first,
        ?NonConformityVerification $second,
    ): ?NonConformityVerification {
        return match (true) {
            $first === null => $second,
            $second === null => $first,
            default => (int) $first->getKey() >= (int) $second->getKey() ? $first : $second,
        };
    }

    protected function lock(NonConformity $nonConformity): NonConformity
    {
        $locked = NonConformity::query()->lockForUpdate()->find($nonConformity->getKey());

        if ($locked === null) {
            $this->fail('non_conformity', 'Cette non-conformité n\'existe plus.');
        }

        return $locked;
    }

    protected function auditTransition(
        NonConformity $nonConformity,
        NonConformityStatus $from,
        NonConformityStatus $to,
        User $actor,
        ?string $comment,
    ): void {
        $this->audit->record(
            QualityAuditEvent::NonConformityStatusChanged,
            QualityAuditSubject::NonConformity,
            (int) $nonConformity->getKey(),
            $nonConformity->reference,
            $actor,
            $nonConformity->workOrder,
            $from->value,
            $to->value,
            $comment,
        );
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
