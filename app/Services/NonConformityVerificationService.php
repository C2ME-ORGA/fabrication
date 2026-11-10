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
use App\Models\WorkOrderQualityControl;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Enregistrement des vérifications et contre-contrôles d'une NC.
 */
class NonConformityVerificationService
{
    public function __construct(
        protected QualityAuditService $audit,
        protected CorrectiveActionService $correctiveActions,
        protected NonConformityLifecycleService $lifecycle,
        protected Gate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(NonConformity $nonConformity, array $data, User $actor): NonConformityVerification
    {
        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(NonConformityVerificationType::class)],
            'result' => ['required', Rule::enum(NonConformityVerificationResult::class)],
            'performed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'comment' => ['required', 'string', 'min:3', 'max:10000'],
            'counter_control_id' => ['nullable', 'integer', Rule::exists('work_order_quality_controls', 'id')],
        ])->validate();

        $type = NonConformityVerificationType::from($validated['type']);
        $result = NonConformityVerificationResult::from($validated['result']);
        $performedAt = isset($validated['performed_at'])
            ? Carbon::parse($validated['performed_at'])->utc()
            : now();

        return DB::transaction(function () use ($nonConformity, $validated, $type, $result, $performedAt, $actor): NonConformityVerification {
            $locked = NonConformity::query()->lockForUpdate()->find($nonConformity->getKey());

            if ($locked === null) {
                $this->fail('non_conformity', 'Cette non-conformité n\'existe plus.');
            }

            if (! $this->gate->forUser($actor)->allows('verify', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à vérifier cette non-conformité.');
            }

            if ($locked->status !== NonConformityStatus::PendingVerification) {
                $this->fail('status', 'Une vérification ne peut être enregistrée qu\'en attente de vérification.');
            }

            if ($locked->isMajorOrCritical()) {
                if ((int) $locked->detected_by === (int) $actor->getKey()
                    || (int) $locked->created_by === (int) $actor->getKey()) {
                    $this->fail(
                        'verification',
                        'La personne ayant déclaré la non-conformité ne peut pas réaliser sa vérification.',
                    );
                }
            }

            $counterControlId = isset($validated['counter_control_id']) && $validated['counter_control_id'] !== null
                ? (int) $validated['counter_control_id']
                : null;

            if ($counterControlId !== null) {
                $control = WorkOrderQualityControl::query()
                    ->where('work_order_id', $locked->work_order_id)
                    ->whereKey($counterControlId)
                    ->first();

                if ($control === null) {
                    $this->fail('counter_control_id', 'Le contre-contrôle sélectionné n\'appartient pas à cet OF.');
                }
            }

            $verification = new NonConformityVerification;
            $verification->forceFill([
                'non_conformity_id' => $locked->getKey(),
                'type' => $type->value,
                'performed_by' => $actor->getKey(),
                'performed_at' => $performedAt,
                'result' => $result->value,
                'comment' => $validated['comment'],
                'counter_control_id' => $counterControlId,
                'created_at' => now(),
            ])->save();

            $this->audit->record(
                QualityAuditEvent::VerificationRecorded,
                QualityAuditSubject::Verification,
                (int) $verification->getKey(),
                $this->verificationReference($locked, $verification),
                $actor,
                $locked->workOrder,
                $locked->status->value,
                $locked->status->value,
                $validated['comment'],
                [
                    'non_conformity_id' => $locked->getKey(),
                    'type' => $type->value,
                    'result' => $result->value,
                    'counter_control_id' => $counterControlId,
                ],
            );

            if ($result === NonConformityVerificationResult::NonConforming) {
                $this->lifecycle->returnToTreatment(
                    $locked,
                    $actor,
                    'Vérification non conforme : retour en traitement.',
                );
            } elseif ($type === NonConformityVerificationType::Effectiveness) {
                $this->correctiveActions->markVerifiedForNonConformity($locked->refresh(), $actor);
            }

            return $verification->refresh();
        }, attempts: 3);
    }

    protected function verificationReference(
        NonConformity $nonConformity,
        NonConformityVerification $verification,
    ): string {
        return $nonConformity->reference.' / vérification #'.$verification->getKey();
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
