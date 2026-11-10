<?php

namespace App\Services;

use App\Enums\CorrectiveActionStatus;
use App\Enums\NonConformityStatus;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Models\NonConformity;
use App\Models\NonConformityCorrectiveAction;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des actions correctives d'une non-conformité.
 */
class CorrectiveActionService
{
    public function __construct(
        protected NonConformityService $nonConformities,
        protected NonConformityLifecycleService $lifecycle,
        protected QualityAuditService $audit,
        protected Gate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(NonConformity $nonConformity, array $data, User $actor): NonConformityCorrectiveAction
    {
        $validated = Validator::make($data, [
            'description' => ['required', 'string', 'min:3', 'max:10000'],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'verification_required' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($nonConformity, $validated, $actor): NonConformityCorrectiveAction {
            $locked = $this->lockNonConformity($nonConformity);

            if (! $this->gate->forUser($actor)->allows('manageCorrectiveActions', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à ajouter une action corrective.');
            }

            if (! in_array($locked->status, [
                NonConformityStatus::Open,
                NonConformityStatus::InProgress,
            ], true)) {
                $this->fail('status', 'Une action corrective ne peut être ajoutée qu\'en cours de traitement.');
            }

            $action = new NonConformityCorrectiveAction;
            $action->forceFill([
                'non_conformity_id' => $locked->getKey(),
                'position' => ((int) $locked->correctiveActions()->max('position')) + 10,
                'description' => $validated['description'],
                'responsible_id' => $validated['responsible_id'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'status' => CorrectiveActionStatus::Planned->value,
                'verification_required' => $validated['verification_required'] ?? true,
            ])->save();

            $this->audit->record(
                QualityAuditEvent::CorrectiveActionAdded,
                QualityAuditSubject::CorrectiveAction,
                (int) $action->getKey(),
                $this->actionReference($locked, $action),
                $actor,
                $locked->workOrder,
                null,
                CorrectiveActionStatus::Planned->value,
                null,
                [
                    'non_conformity_id' => $locked->getKey(),
                    'description' => $action->description,
                    'responsible_id' => $action->responsible_id,
                    'due_date' => $action->due_date?->toDateString(),
                ],
            );

            return $action->refresh();
        }, attempts: 3);
    }

    public function start(NonConformityCorrectiveAction $action, User $actor): NonConformityCorrectiveAction
    {
        return $this->transition($action, $actor, CorrectiveActionStatus::InProgress, null, 'Action corrective démarrée.');
    }

    public function complete(
        NonConformityCorrectiveAction $action,
        User $actor,
        string $evidenceComment,
    ): NonConformityCorrectiveAction {
        $evidenceComment = trim($evidenceComment);

        if ($evidenceComment === '') {
            $this->fail('evidence_comment', 'La preuve de réalisation est obligatoire pour terminer une action corrective.');
        }

        return $this->transition(
            $action,
            $actor,
            CorrectiveActionStatus::Done,
            $evidenceComment,
            'Action corrective terminée.',
            [
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'evidence_comment' => $evidenceComment,
            ],
        );
    }

    public function cancel(
        NonConformityCorrectiveAction $action,
        User $actor,
        string $reason,
    ): NonConformityCorrectiveAction {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason', 'Le motif d\'annulation est obligatoire.');
        }

        return $this->transition(
            $action,
            $actor,
            CorrectiveActionStatus::Cancelled,
            $reason,
            'Action corrective annulée.',
        );
    }

    public function markIneffective(
        NonConformityCorrectiveAction $action,
        User $actor,
        string $reason,
    ): NonConformityCorrectiveAction {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason', 'Le motif d\'inefficacité est obligatoire.');
        }

        $updated = $this->transition(
            $action,
            $actor,
            CorrectiveActionStatus::Ineffective,
            $reason,
            'Action corrective déclarée inefficace.',
        );

        $nonConformity = $updated->nonConformity;

        if ($nonConformity->status === NonConformityStatus::PendingVerification) {
            $this->lifecycle->returnToTreatment(
                $nonConformity,
                $actor,
                'Action corrective inefficace : retour en traitement.',
            );
        }

        return $updated->refresh();
    }

    /**
     * Passe les actions terminées à « vérifiée » après une vérification
     * d'efficacité conforme.
     */
    public function markVerifiedForNonConformity(NonConformity $nonConformity, User $actor): void
    {
        DB::transaction(function () use ($nonConformity, $actor): void {
            $locked = $this->lockNonConformity($nonConformity);

            if (! $this->gate->forUser($actor)->allows('verify', $locked)) {
                $this->fail('verification', 'Vous n\'êtes pas autorisé à vérifier les actions correctives.');
            }

            $actions = $locked->correctiveActions()
                ->where('status', CorrectiveActionStatus::Done->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($actions as $action) {
                $action->forceFill(['status' => CorrectiveActionStatus::Verified->value])->save();

                $this->audit->record(
                    QualityAuditEvent::CorrectiveActionStatusChanged,
                    QualityAuditSubject::CorrectiveAction,
                    (int) $action->getKey(),
                    $this->actionReference($locked, $action),
                    $actor,
                    $locked->workOrder,
                    CorrectiveActionStatus::Done->value,
                    CorrectiveActionStatus::Verified->value,
                    'Action corrective vérifiée après une vérification d\'efficacité conforme.',
                );
            }
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $extraAttributes
     */
    protected function transition(
        NonConformityCorrectiveAction $action,
        User $actor,
        CorrectiveActionStatus $target,
        ?string $comment,
        string $auditComment,
        array $extraAttributes = [],
    ): NonConformityCorrectiveAction {
        return DB::transaction(function () use ($action, $actor, $target, $comment, $extraAttributes): NonConformityCorrectiveAction {
            $nonConformity = $this->lockNonConformity(
                NonConformity::query()->findOrFail($action->non_conformity_id),
            );
            $locked = $this->lockAction($action);

            if ((int) $locked->non_conformity_id !== (int) $nonConformity->getKey()) {
                $this->fail('corrective_action', 'Cette action corrective n\'appartient pas à cette non-conformité.');
            }

            if (! $this->gate->forUser($actor)->allows('update', $locked)) {
                $this->fail('corrective_action', 'Vous n\'êtes pas autorisé à modifier cette action corrective.');
            }

            $from = $locked->status;

            if (! $this->canTransition($from, $target)) {
                $this->fail(
                    'status',
                    "Transition d'action corrective interdite : « {$from->getLabel()} » vers « {$target->getLabel()} ».",
                );
            }

            $locked->forceFill([
                'status' => $target->value,
                ...$extraAttributes,
            ])->save();

            $event = match ($target) {
                CorrectiveActionStatus::Done => QualityAuditEvent::CorrectiveActionCompleted,
                CorrectiveActionStatus::Ineffective => QualityAuditEvent::CorrectiveActionIneffective,
                default => QualityAuditEvent::CorrectiveActionStatusChanged,
            };

            $this->audit->record(
                $event,
                QualityAuditSubject::CorrectiveAction,
                (int) $locked->getKey(),
                $this->actionReference($nonConformity, $locked),
                $actor,
                $nonConformity->workOrder,
                $from->value,
                $target->value,
                $comment,
                $extraAttributes,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    protected function canTransition(CorrectiveActionStatus $from, CorrectiveActionStatus $to): bool
    {
        return match ($from) {
            CorrectiveActionStatus::Planned => in_array($to, [
                CorrectiveActionStatus::InProgress,
                CorrectiveActionStatus::Done,
                CorrectiveActionStatus::Cancelled,
            ], true),
            CorrectiveActionStatus::InProgress => in_array($to, [
                CorrectiveActionStatus::Done,
                CorrectiveActionStatus::Ineffective,
                CorrectiveActionStatus::Cancelled,
            ], true),
            CorrectiveActionStatus::Done => in_array($to, [
                CorrectiveActionStatus::Ineffective,
                CorrectiveActionStatus::Verified,
            ], true),
            default => false,
        };
    }

    protected function lockAction(NonConformityCorrectiveAction $action): NonConformityCorrectiveAction
    {
        $locked = NonConformityCorrectiveAction::query()->lockForUpdate()->find($action->getKey());

        if ($locked === null) {
            $this->fail('corrective_action', 'Cette action corrective n\'existe plus.');
        }

        return $locked;
    }

    protected function lockNonConformity(NonConformity $nonConformity): NonConformity
    {
        $locked = NonConformity::query()->lockForUpdate()->find($nonConformity->getKey());

        if ($locked === null) {
            $this->fail('non_conformity', 'Cette non-conformité n\'existe plus.');
        }

        return $locked;
    }

    protected function actionReference(NonConformity $nonConformity, NonConformityCorrectiveAction $action): string
    {
        return $nonConformity->reference.' / action #'.$action->getKey();
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
