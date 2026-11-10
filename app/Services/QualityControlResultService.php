<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityResultDecision;
use App\Models\QualityControlResult;
use App\Models\QualityControlResultAttachment;
use App\Models\QualityControlResultValidation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saisie, évaluation et validation des résultats de contrôle.
 *
 * Toutes les règles métier sont appliquées ici : type de valeur, appartenance
 * du repère à l'OF, échantillonnage déterministe, horodatage, ségrégation des
 * tâches et double validation des points critiques.
 */
class QualityControlResultService
{
    public function __construct(
        protected WorkOrderService $workOrders,
        protected QualityToleranceEvaluator $evaluator,
        protected QualityControlEvaluationService $evaluation,
        protected QualityEvidenceStorageService $evidence,
        protected QualityAuditService $audit,
        protected Gate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(
        WorkOrderQualityControlPoint $point,
        array $data,
        User $actor,
    ): QualityControlResult {
        return DB::transaction(function () use ($point, $data, $actor): QualityControlResult {
            [$workOrder, $control, $lockedPoint] = $this->lockContext($point, $actor, 'recordResult');

            $payload = $this->validatePayload($workOrder, $control, $lockedPoint, $data);

            $evaluation = $this->evaluator->evaluate($lockedPoint, [
                'value_boolean' => $payload['value_boolean'] ?? null,
                'value_numeric' => $payload['value_numeric'] ?? null,
                'value_text' => $payload['value_text'] ?? null,
                'value_choice' => $payload['value_choice'] ?? null,
                'is_conform' => $payload['is_conform'] ?? null,
            ]);

            $result = new QualityControlResult;
            $result->forceFill([
                'work_order_quality_control_point_id' => $lockedPoint->getKey(),
                'work_order_id' => $workOrder->getKey(),
                'work_order_item_id' => $payload['work_order_item_id'],
                'performed_by' => $actor->getKey(),
                'performed_at' => $payload['performed_at'],
                'value_boolean' => $payload['value_boolean'],
                'value_numeric' => $payload['value_numeric'],
                'value_text' => $payload['value_text'],
                'value_choice' => $payload['value_choice'],
                'unit_snapshot' => $lockedPoint->unit,
                'tolerance_min_snapshot' => $lockedPoint->tolerance_min,
                'tolerance_max_snapshot' => $lockedPoint->tolerance_max,
                'is_conform' => $evaluation->isConform,
                'evaluation_detail' => $evaluation->detail,
                'comment' => $payload['comment'],
                'supersedes_result_id' => $payload['supersedes_result_id'],
                'recorded_by' => $actor->getKey(),
                'created_at' => now(),
            ])->save();

            $storedEvidence = [];

            try {
                foreach ($payload['evidence'] as $file) {
                    $attachment = $this->persistEvidence($result, $lockedPoint, $control, $file, $actor);
                    $storedEvidence[] = $attachment;
                }
            } catch (Throwable $exception) {
                foreach ($storedEvidence as $attachment) {
                    $this->evidence->delete($attachment->disk, $attachment->path);
                }

                throw $exception;
            }

            $this->audit->record(
                QualityAuditEvent::ResultRecorded,
                QualityAuditSubject::Result,
                (int) $result->getKey(),
                $this->resultReference($workOrder, $control, $lockedPoint),
                $actor,
                $workOrder,
                $control->status->value,
                $control->status->value,
                null,
                [
                    'control_id' => $control->getKey(),
                    'control_point_id' => $lockedPoint->getKey(),
                    'result_type' => $lockedPoint->result_type->value,
                    'work_order_item_id' => $payload['work_order_item_id'],
                    'is_conform' => $evaluation->isConform,
                    'supersedes_result_id' => $payload['supersedes_result_id'],
                    'evidence_count' => count($storedEvidence),
                    'evaluation_rule' => $this->evaluation->ruleForScope($control->scope),
                    'historical_results_preserved' => true,
                ],
            );

            $this->evaluation->recalculate($control->refresh(), $actor);

            return $result->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validate(
        QualityControlResult $result,
        array|string $decision,
        User $actor,
        ?string $comment = null,
    ): QualityControlResultValidation {
        $decisionValue = is_array($decision) ? ($decision['decision'] ?? null) : $decision;
        $comment = is_array($decision) ? ($decision['comment'] ?? $comment) : $comment;

        $validated = validator(
            ['decision' => $decisionValue],
            ['decision' => ['required', Rule::enum(QualityResultDecision::class)]],
        )->validate();

        $decision = QualityResultDecision::from($validated['decision']);

        return DB::transaction(function () use ($result, $decision, $actor, $comment): QualityControlResultValidation {
            $point = WorkOrderQualityControlPoint::query()->find($result->work_order_quality_control_point_id);

            if ($point === null) {
                $this->fail('result', 'Le point de contrôle de ce résultat est introuvable.');
            }

            [$workOrder, $control, $lockedPoint, $lockedResult] = $this->lockResult(
                $point,
                $result,
                $actor,
                'validateResult',
            );

            $existing = $lockedResult->validations()->orderBy('id')->get();

            if ($existing->contains(
                fn (QualityControlResultValidation $validation): bool => $validation->decision === QualityResultDecision::Rejected,
            )) {
                $this->fail('decision', 'Ce résultat a déjà été rejeté : un nouveau résultat est nécessaire.');
            }

            $required = $lockedPoint->requiredValidations();

            if ($existing->count() >= $required) {
                $this->fail(
                    'decision',
                    'Ce résultat a déjà atteint le nombre de validations requis. Saisissez un nouveau résultat pour toute correction.',
                );
            }

            if ($decision === QualityResultDecision::Validated) {
                if ((int) $lockedResult->performed_by === (int) $actor->getKey()) {
                    $this->fail(
                        'decision',
                        'Le contrôleur ne peut pas valider son propre résultat (ségrégation des tâches).',
                    );
                }

                if ($existing->contains(
                    fn (QualityControlResultValidation $validation): bool => (int) $validation->validated_by === (int) $actor->getKey(),
                )) {
                    $this->fail('decision', 'Vous avez déjà validé ce résultat.');
                }
            }

            if ($decision === QualityResultDecision::Rejected && trim((string) $comment) === '') {
                $this->fail('comment', 'Un rejet doit être motivé par un commentaire.');
            }

            $validation = new QualityControlResultValidation;
            $validation->forceFill([
                'quality_control_result_id' => $lockedResult->getKey(),
                'decision' => $decision->value,
                'validated_by' => $actor->getKey(),
                'validated_at' => now(),
                'comment' => $comment,
                'created_at' => now(),
            ])->save();

            $this->audit->record(
                $decision === QualityResultDecision::Validated
                    ? QualityAuditEvent::ResultValidated
                    : QualityAuditEvent::ResultRejected,
                QualityAuditSubject::Result,
                (int) $lockedResult->getKey(),
                $this->resultReference($workOrder, $control, $lockedPoint),
                $actor,
                $workOrder,
                null,
                $decision->value,
                $comment,
                [
                    'control_id' => $control->getKey(),
                    'control_point_id' => $lockedPoint->getKey(),
                    'rank' => $existing->count() + 1,
                    'required_validations' => $required,
                    'is_critical' => (bool) $lockedPoint->is_critical,
                    'evaluation_rule' => $this->evaluation->ruleForScope($control->scope),
                    'historical_results_preserved' => true,
                ],
            );

            $this->evaluation->recalculate($control->refresh(), $actor);

            return $validation->refresh();
        }, attempts: 3);
    }

    public function attachEvidence(
        QualityControlResult $result,
        UploadedFile $file,
        User $actor,
    ): QualityControlResultAttachment {
        return DB::transaction(function () use ($result, $file, $actor): QualityControlResultAttachment {
            $point = WorkOrderQualityControlPoint::query()->find($result->work_order_quality_control_point_id);

            if ($point === null) {
                $this->fail('result', 'Le point de contrôle de ce résultat est introuvable.');
            }

            [, $control, $lockedPoint] = $this->lockContext($point, $actor, 'recordResult');

            $lockedResult = $lockedPoint->results()->whereKey($result->getKey())->first();

            if ($lockedResult === null) {
                $this->fail('result', 'Ce résultat n\'appartient pas au point de contrôle.');
            }

            return $this->persistEvidence($lockedResult, $lockedPoint, $control, $file, $actor);
        }, attempts: 3);
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderQualityControl, 2: WorkOrderQualityControlPoint}
     */
    protected function lockContext(
        WorkOrderQualityControlPoint $point,
        User $actor,
        string $ability,
    ): array {
        $control = WorkOrderQualityControl::query()->find($point->work_order_quality_control_id);

        if ($control === null) {
            $this->fail('quality_control', 'Le contrôle qualité de ce point est introuvable.');
        }

        $workOrder = WorkOrder::query()->lockForUpdate()->find($control->work_order_id);

        if ($workOrder === null) {
            $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
        }

        if (! $workOrder->status->allowsOperationExecution()) {
            $this->fail(
                'status',
                "L'ordre de fabrication {$workOrder->number} doit être libéré pour saisir ou valider un résultat qualité.",
            );
        }

        $lockedControl = WorkOrderQualityControl::query()->lockForUpdate()->find($control->getKey());
        $lockedControl->setRelation('workOrder', $workOrder);

        $lockedPoint = $lockedControl->points()
            ->whereKey($point->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedPoint === null) {
            $this->fail('control_point', 'Ce point n\'appartient pas au contrôle qualité.');
        }

        if (! $this->gate->forUser($actor)->allows($ability, $lockedControl)) {
            $this->fail('quality_control', 'Vous n\'êtes pas autorisé à effectuer cette action sur ce contrôle.');
        }

        if (! $lockedControl->allowsResultEntry()) {
            $this->fail(
                'status',
                "Le contrôle « {$lockedControl->status->getLabel()} » n'accepte plus de résultat.",
            );
        }

        return [$workOrder, $lockedControl, $lockedPoint];
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderQualityControl, 2: WorkOrderQualityControlPoint, 3: QualityControlResult}
     */
    protected function lockResult(
        WorkOrderQualityControlPoint $point,
        QualityControlResult $result,
        User $actor,
        string $ability,
    ): array {
        $control = WorkOrderQualityControl::query()->find($point->work_order_quality_control_id);

        if ($control === null) {
            $this->fail('quality_control', 'Le contrôle qualité de ce point est introuvable.');
        }

        $workOrder = WorkOrder::query()->lockForUpdate()->find($control->work_order_id);

        if ($workOrder === null) {
            $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
        }

        if (! $workOrder->status->allowsOperationExecution()) {
            $this->fail(
                'status',
                "L'ordre de fabrication {$workOrder->number} doit être libéré pour saisir ou valider un résultat qualité.",
            );
        }

        $lockedControl = WorkOrderQualityControl::query()->lockForUpdate()->find($control->getKey());
        $lockedControl->setRelation('workOrder', $workOrder);

        $lockedPoint = $lockedControl->points()
            ->whereKey($point->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedPoint === null) {
            $this->fail('control_point', 'Ce point n\'appartient pas au contrôle qualité.');
        }

        $lockedResult = $lockedPoint->results()
            ->whereKey($result->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedResult === null) {
            $this->fail('result', 'Ce résultat n\'appartient pas au point de contrôle.');
        }

        if (! $this->gate->forUser($actor)->allows($ability, $lockedControl)) {
            $this->fail('quality_control', 'Vous n\'êtes pas autorisé à effectuer cette action sur ce contrôle.');
        }

        if ($lockedControl->isCancelled()) {
            $this->fail('status', 'Ce contrôle a été annulé.');
        }

        return [$workOrder, $lockedControl, $lockedPoint, $lockedResult];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validatePayload(
        WorkOrder $workOrder,
        WorkOrderQualityControl $control,
        WorkOrderQualityControlPoint $point,
        array $data,
    ): array {
        $performedAt = $this->resolvePerformedAt($data['performed_at'] ?? null);

        if ($performedAt->isAfter(now()->addMinutes(5))) {
            $this->fail('performed_at', 'La date de réalisation ne peut pas être dans le futur.');
        }

        if ($workOrder->released_at !== null && $performedAt->lt($workOrder->released_at)) {
            $this->fail('performed_at', 'La date de réalisation ne peut pas précéder la libération de l\'ordre de fabrication.');
        }

        $itemId = $data['work_order_item_id'] ?? null;
        $itemId = $itemId === null || $itemId === '' ? null : (int) $itemId;

        if ($control->scope === QualityRequirementScope::Item && $itemId === null) {
            $this->fail('work_order_item_id', 'Un contrôle au niveau du repère exige la sélection d\'un repère.');
        }

        if ($itemId !== null) {
            $item = WorkOrderItem::query()
                ->where('work_order_id', $workOrder->getKey())
                ->whereKey($itemId)
                ->first();

            if ($item === null) {
                $this->fail('work_order_item_id', 'Le repère sélectionné n\'appartient pas à cet ordre de fabrication.');
            }

            $sampledIds = $point->sampledItemIds();

            if ($point->sampling_mode === QualityControlSamplingMode::EveryN
                && $sampledIds !== []
                && ! in_array($itemId, $sampledIds, true)) {
                $this->fail(
                    'work_order_item_id',
                    'Ce repère ne fait pas partie de l\'échantillonnage déterministe prévu pour ce point.',
                );
            }
        }

        $payload = [
            'value_boolean' => null,
            'value_numeric' => null,
            'value_text' => null,
            'value_choice' => null,
            'is_conform' => null,
            'work_order_item_id' => $itemId,
            'performed_at' => $performedAt,
            'comment' => isset($data['comment']) ? trim((string) $data['comment']) : null,
            'supersedes_result_id' => null,
            'evidence' => [],
        ];

        $payload['comment'] = $payload['comment'] === '' ? null : $payload['comment'];

        switch ($point->result_type) {
            case QualityControlResultType::Conformity:
                $boolean = $this->booleanValue($data['value_boolean'] ?? null);

                if ($boolean === null) {
                    $this->fail('value_boolean', 'Indiquez si le contrôle est conforme ou non conforme.');
                }

                $payload['value_boolean'] = $boolean;
                break;

            case QualityControlResultType::Numeric:
                $value = $data['value_numeric'] ?? null;

                if ($value === null || $value === '' || ! is_numeric($value)) {
                    $this->fail('value_numeric', 'Une mesure numérique valide est obligatoire.');
                }

                $payload['value_numeric'] = (string) $value;
                break;

            case QualityControlResultType::Text:
                $text = trim((string) ($data['value_text'] ?? ''));

                if ($text === '') {
                    $this->fail('value_text', 'Le texte constaté est obligatoire.');
                }

                $conform = $this->booleanValue($data['is_conform'] ?? null);

                if ($conform === null) {
                    $this->fail('is_conform', 'La conformité constatée doit être indiquée explicitement pour un contrôle texte.');
                }

                $payload['value_text'] = $text;
                $payload['is_conform'] = $conform;
                break;

            case QualityControlResultType::Choice:
                $choice = $data['value_choice'] ?? null;

                if (! is_string($choice) || $choice === '') {
                    $this->fail('value_choice', 'Sélectionnez une option.');
                }

                $payload['value_choice'] = $choice;
                break;
        }

        if (isset($data['supersedes_result_id']) && $data['supersedes_result_id'] !== '') {
            $superseded = $point->results()->whereKey((int) $data['supersedes_result_id'])->first();

            if ($superseded === null) {
                $this->fail('supersedes_result_id', 'Le résultat remplacé n\'appartient pas à ce point de contrôle.');
            }

            $payload['supersedes_result_id'] = $superseded->getKey();
        }

        if (isset($data['evidence'])) {
            $files = is_array($data['evidence']) ? $data['evidence'] : [$data['evidence']];
            $payload['evidence'] = array_values(array_filter(
                $files,
                fn (mixed $file): bool => $file instanceof UploadedFile,
            ));
        }

        return $payload;
    }

    protected function resolvePerformedAt(mixed $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->utc();
        }

        if ($value === null || $value === '') {
            return now();
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            $this->fail('performed_at', 'La date de réalisation est invalide.');
        }
    }

    protected function booleanValue(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return null;
    }

    protected function persistEvidence(
        QualityControlResult $result,
        WorkOrderQualityControlPoint $point,
        WorkOrderQualityControl $control,
        UploadedFile $file,
        User $actor,
    ): QualityControlResultAttachment {
        $stored = $this->evidence->store($file, $control);

        try {
            $attachment = new QualityControlResultAttachment;
            $attachment->forceFill([
                'quality_control_result_id' => $result->getKey(),
                'work_order_id' => $control->work_order_id,
                ...$stored,
                'uploaded_by' => $actor->getKey(),
                'created_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->evidence->delete($stored['disk'], $stored['path']);

            throw $exception;
        }

        return $attachment->refresh();
    }

    protected function resultReference(
        WorkOrder $workOrder,
        WorkOrderQualityControl $control,
        WorkOrderQualityControlPoint $point,
    ): string {
        return Str::limit(
            $workOrder->number.' / contrôle #'.$control->getKey().' / '.$point->label,
            80,
            '',
        );
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
