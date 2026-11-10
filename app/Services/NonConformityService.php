<?php

namespace App\Services;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformityOrigin;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Models\NonConformity;
use App\Models\NonConformityAttachment;
use App\Models\NonConformityStatusHistory;
use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Création, qualification et affectation des non-conformités.
 *
 * Le snapshot initial de politique est copié à la création et reste immuable.
 * Une requalification explicite met à jour la gravité courante et le snapshot
 * courant, sans jamais écraser les informations historiques de création.
 * Les résultats de contrôle ne sont jamais modifiés : la NC conserve seulement
 * leur identifiant.
 */
class NonConformityService
{
    public function __construct(
        protected QualityReferenceGenerator $references,
        protected QualitySeverityPolicyService $severityPolicies,
        protected QualityEvidenceStorageService $evidence,
        protected QualityAuditService $audit,
        protected Gate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromResult(
        QualityControlResult $result,
        array $data,
        User $actor,
    ): NonConformity {
        return DB::transaction(function () use ($result, $data, $actor): NonConformity {
            $point = WorkOrderQualityControlPoint::query()
                ->find($result->work_order_quality_control_point_id);

            if ($point === null) {
                $this->fail('result', 'Le point de contrôle de ce résultat est introuvable.');
            }

            $control = WorkOrderQualityControl::query()->find($point->work_order_quality_control_id);

            if ($control === null) {
                $this->fail('quality_control', 'Le contrôle qualité de ce résultat est introuvable.');
            }

            $workOrder = WorkOrder::query()->lockForUpdate()->find($control->work_order_id);

            if ($workOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            // Le verrou sur le résultat sérialise les déclarations concurrentes.
            $lockedResult = QualityControlResult::query()
                ->whereKey($result->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedResult === null) {
                $this->fail('result', 'Le résultat de contrôle est introuvable.');
            }

            if ($lockedResult->is_conform !== false) {
                $this->fail('result', 'Une non-conformité ne peut être déclarée que depuis un résultat non conforme.');
            }

            if ($lockedResult->fresh()->isRejected()) {
                $this->fail('result', 'Un résultat rejeté ne peut pas déclencher de non-conformité.');
            }

            $this->assertResultHasNoNonConformity($lockedResult);
            $this->authorizeCreate($actor);

            return $this->persist(
                workOrder: $workOrder,
                context: [
                    'origin' => NonConformityOrigin::ControlResult,
                    'work_order_item_id' => $lockedResult->work_order_item_id,
                    'work_order_operation_id' => $control->work_order_operation_id,
                    'work_order_quality_control_id' => $control->getKey(),
                    'quality_control_result_id' => $lockedResult->getKey(),
                ],
                data: $data,
                actor: $actor,
            );
        }, attempts: 3);
    }

    /**
     * Déclaration depuis un contrôle en échec. Si un résultat non conforme
     * est fourni, la création passe par le chemin « depuis résultat ».
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromControl(
        WorkOrderQualityControl $control,
        array $data,
        User $actor,
    ): NonConformity {
        if (isset($data['quality_control_result_id']) && $data['quality_control_result_id'] !== '') {
            $result = QualityControlResult::query()->find((int) $data['quality_control_result_id']);

            if ($result === null) {
                $this->fail('quality_control_result_id', 'Le résultat sélectionné est introuvable.');
            }

            $point = WorkOrderQualityControlPoint::query()
                ->find($result->work_order_quality_control_point_id);

            if ($point === null || (int) $point->work_order_quality_control_id !== (int) $control->getKey()) {
                $this->fail('quality_control_result_id', 'Le résultat ne dépend pas de ce contrôle.');
            }

            return $this->createFromResult($result, $data, $actor);
        }

        return DB::transaction(function () use ($control, $data, $actor): NonConformity {
            $workOrder = WorkOrder::query()->lockForUpdate()->find($control->work_order_id);

            if ($workOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            $lockedControl = WorkOrderQualityControl::query()
                ->whereKey($control->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedControl === null) {
                $this->fail('quality_control', 'Ce contrôle n\'appartient pas à cet ordre de fabrication.');
            }

            if ($lockedControl->status !== QualityControlStatus::Failed) {
                $this->fail('quality_control', 'Une non-conformité ne peut être déclarée depuis un contrôle en échec que lorsqu\'il est non conforme.');
            }

            $this->authorizeCreate($actor);

            $origin = match ($lockedControl->scope) {
                QualityRequirementScope::Item => NonConformityOrigin::Item,
                QualityRequirementScope::WorkOrder => NonConformityOrigin::WorkOrder,
                default => NonConformityOrigin::Operation,
            };

            return $this->persist(
                workOrder: $workOrder,
                context: [
                    'origin' => $origin,
                    'work_order_item_id' => $lockedControl->work_order_item_id,
                    'work_order_operation_id' => $lockedControl->work_order_operation_id,
                    'work_order_quality_control_id' => $lockedControl->getKey(),
                    'quality_control_result_id' => null,
                ],
                data: $data,
                actor: $actor,
            );
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createManual(WorkOrder $workOrder, array $data, User $actor): NonConformity
    {
        return DB::transaction(function () use ($workOrder, $data, $actor): NonConformity {
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->find($workOrder->getKey());

            if ($lockedWorkOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            $this->authorizeWorkOrderAccess($lockedWorkOrder, $actor);

            $operationId = isset($data['work_order_operation_id']) && $data['work_order_operation_id'] !== ''
                ? (int) $data['work_order_operation_id']
                : null;

            if ($operationId !== null) {
                $operation = WorkOrderOperation::query()
                    ->where('work_order_id', $lockedWorkOrder->getKey())
                    ->whereKey($operationId)
                    ->first();

                if ($operation === null) {
                    $this->fail('work_order_operation_id', 'L\'opération sélectionnée n\'appartient pas à cet OF.');
                }
            }

            $itemId = isset($data['work_order_item_id']) && $data['work_order_item_id'] !== ''
                ? (int) $data['work_order_item_id']
                : null;

            if ($itemId !== null) {
                $item = WorkOrderItem::query()
                    ->where('work_order_id', $lockedWorkOrder->getKey())
                    ->whereKey($itemId)
                    ->first();

                if ($item === null) {
                    $this->fail('work_order_item_id', 'Le repère sélectionné n\'appartient pas à cet OF.');
                }
            }

            $controlId = isset($data['work_order_quality_control_id']) && $data['work_order_quality_control_id'] !== ''
                ? (int) $data['work_order_quality_control_id']
                : null;

            if ($controlId !== null) {
                $control = WorkOrderQualityControl::query()
                    ->where('work_order_id', $lockedWorkOrder->getKey())
                    ->whereKey($controlId)
                    ->first();

                if ($control === null) {
                    $this->fail('work_order_quality_control_id', 'Le contrôle sélectionné n\'appartient pas à cet OF.');
                }
            }

            $this->authorizeCreate($actor);

            $origin = isset($data['origin'])
                ? NonConformityOrigin::from((string) $data['origin'])
                : ($itemId !== null
                    ? NonConformityOrigin::Item
                    : ($operationId !== null ? NonConformityOrigin::Operation : NonConformityOrigin::WorkOrder));

            return $this->persist(
                workOrder: $lockedWorkOrder,
                context: [
                    'origin' => $origin,
                    'work_order_item_id' => $itemId,
                    'work_order_operation_id' => $operationId,
                    'work_order_quality_control_id' => $controlId,
                    'quality_control_result_id' => null,
                ],
                data: $data,
                actor: $actor,
            );
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assign(NonConformity $nonConformity, array $data, User $actor): NonConformity
    {
        $validated = Validator::make($data, [
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'treatment_plan' => ['nullable', 'string', 'max:10000'],
            'immediate_action' => ['nullable', 'string', 'max:10000'],
            'containment_at' => ['nullable', 'date', 'before_or_equal:now'],
        ])->validate();

        return DB::transaction(function () use ($nonConformity, $validated, $actor): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('assign', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à affecter cette non-conformité.');
            }

            $previous = [
                'responsible_id' => $locked->responsible_id,
                'due_date' => $locked->due_date?->toDateString(),
                'treatment_plan' => $locked->treatment_plan,
                'immediate_action' => $locked->immediate_action,
                'containment_at' => $locked->containment_at?->toIso8601String(),
            ];

            $attributes = [];

            foreach (['responsible_id', 'due_date', 'treatment_plan', 'immediate_action', 'containment_at'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $attributes[$field] = $validated[$field];
                }
            }

            if ($attributes === []) {
                return $locked->refresh();
            }

            $locked->forceFill($attributes)->save();

            $this->audit->record(
                QualityAuditEvent::NonConformityAssigned,
                QualityAuditSubject::NonConformity,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                $locked->status->value,
                $locked->status->value,
                'Affectation et traitement envisagé.',
                [
                    'previous' => $previous,
                    'current' => [
                        'responsible_id' => $locked->responsible_id,
                        'due_date' => $locked->due_date?->toDateString(),
                        'treatment_plan' => $locked->treatment_plan,
                        'immediate_action' => $locked->immediate_action,
                        'containment_at' => $locked->containment_at?->toIso8601String(),
                    ],
                ],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Confirme ou modifie la gravité. Une requalification explicite produit
     * un nouveau snapshot de politique et reste intégralement auditée.
     *
     * @param  array<string, mixed>  $data
     */
    public function qualify(NonConformity $nonConformity, array $data, User $actor): NonConformity
    {
        $validated = Validator::make($data, [
            'severity' => ['required', Rule::enum(NonConformitySeverity::class)],
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
        ])->validate();

        $targetSeverity = NonConformitySeverity::from($validated['severity']);
        $reason = trim((string) $validated['reason']);

        return DB::transaction(function () use ($nonConformity, $targetSeverity, $reason, $actor): NonConformity {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('qualify', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à qualifier cette non-conformité.');
            }

            if (($locked->isMajorOrCritical() || $targetSeverity->requiresQualityManager())
                && ! $actor->canCloseMajorNonConformities()) {
                $this->fail(
                    'severity',
                    'Seul un responsable qualité ou un administrateur peut qualifier une non-conformité majeure ou critique.',
                );
            }

            $previousSeverity = $locked->severity;
            $previousSnapshot = $locked->currentPolicy();
            $initialSnapshot = $locked->severity_policy_snapshot;

            $locked->qualified_by = $actor->getKey();
            $locked->qualified_at = now();
            $locked->qualification_comment = $reason;

            if ($targetSeverity !== $previousSeverity) {
                $snapshot = $this->severityPolicies->snapshotFor($targetSeverity);
                $detectedAt = $locked->detected_at ?? now();

                $locked->forceFill([
                    'severity' => $targetSeverity->value,
                    'is_blocking' => $snapshot['blocking'],
                    'verification_required' => $snapshot['verification_required'],
                    'counter_control_required' => $snapshot['counter_control_required'],
                    'current_severity_policy_snapshot' => $snapshot,
                    'due_date' => $this->severityPolicies->dueDateFor($snapshot, $detectedAt),
                    'verification_due_at' => $snapshot['verification_required']
                        ? Carbon::instance($detectedAt)->addDays((int) $snapshot['due_days'])
                        : null,
                ]);
            }

            $locked->save();

            $this->audit->record(
                $targetSeverity !== $previousSeverity
                    ? QualityAuditEvent::NonConformitySeverityChanged
                    : QualityAuditEvent::NonConformityQualified,
                QualityAuditSubject::NonConformity,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                $locked->status->value,
                $locked->status->value,
                $reason,
                [
                    'previous_severity' => $previousSeverity->value,
                    'severity' => $targetSeverity->value,
                    'initial_severity' => $locked->initial_severity->value,
                    'previous_policy' => $previousSnapshot,
                    'policy' => $locked->currentPolicy(),
                    'initial_policy' => $initialSnapshot,
                    'reason' => $reason,
                ],
            );

            if ($targetSeverity !== $previousSeverity) {
                $this->audit->record(
                    QualityAuditEvent::SeverityPolicyApplied,
                    QualityAuditSubject::NonConformity,
                    (int) $locked->getKey(),
                    $locked->reference,
                    $actor,
                    $locked->workOrder,
                    null,
                    null,
                    'Nouvelle politique de gravité appliquée après requalification.',
                    [
                        'policy' => $locked->currentPolicy(),
                        'initial_policy' => $locked->severity_policy_snapshot,
                    ],
                );
            }

            return $locked->refresh();
        }, attempts: 3);
    }

    public function attachEvidence(
        NonConformity $nonConformity,
        UploadedFile $file,
        User $actor,
    ): NonConformityAttachment {
        return DB::transaction(function () use ($nonConformity, $file, $actor): NonConformityAttachment {
            $locked = $this->lock($nonConformity);

            if (! $this->gate->forUser($actor)->allows('attachEvidence', $locked)) {
                $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à joindre une preuve à cette non-conformité.');
            }

            $stored = $this->evidence->storeForNonConformity($file, $locked);

            try {
                $attachment = new NonConformityAttachment;
                $attachment->forceFill([
                    'non_conformity_id' => $locked->getKey(),
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
                QualityAuditEvent::NonConformityEvidenceAdded,
                QualityAuditSubject::NonConformity,
                (int) $locked->getKey(),
                $locked->reference,
                $actor,
                $locked->workOrder,
                $locked->status->value,
                $locked->status->value,
                'Ajout d\'une pièce justificative.',
                [
                    'attachment_id' => $attachment->getKey(),
                    'original_filename' => $attachment->original_filename,
                    'sha256' => $attachment->sha256,
                ],
            );

            return $attachment->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $data
     */
    protected function persist(
        WorkOrder $workOrder,
        array $context,
        array $data,
        User $actor,
    ): NonConformity {
        $validated = Validator::make($data, [
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'criterion' => ['nullable', 'string', 'max:5000'],
            'severity' => ['required', Rule::enum(NonConformitySeverity::class)],
            'category' => ['required', Rule::enum(NonConformityCategory::class)],
            'detected_at' => ['nullable', 'date', 'before_or_equal:now'],
            'due_date' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'treatment_plan' => ['nullable', 'string', 'max:10000'],
            'immediate_action' => ['nullable', 'string', 'max:10000'],
            'containment_at' => ['nullable', 'date', 'before_or_equal:now'],
            'root_cause' => ['nullable', 'string', 'max:10000'],
            'root_cause_method' => ['nullable', 'in:5_why,ishikawa,8d,other'],
        ])->validate();

        $severity = NonConformitySeverity::from($validated['severity']);
        $category = NonConformityCategory::from($validated['category']);
        $snapshot = $this->severityPolicies->snapshotFor($severity);
        $detectedAt = isset($validated['detected_at'])
            ? Carbon::parse($validated['detected_at'])->utc()
            : now();

        $dueDate = $validated['due_date'] ?? $this->severityPolicies->dueDateFor($snapshot, $detectedAt);

        $nonConformity = new NonConformity;
        $nonConformity->forceFill([
            'reference' => $this->references->nextNonConformityReference((int) $detectedAt->format('Y')),
            'origin' => $context['origin']->value,
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'work_order_item_id' => $context['work_order_item_id'],
            'work_order_operation_id' => $context['work_order_operation_id'],
            'work_order_quality_control_id' => $context['work_order_quality_control_id'],
            'quality_control_result_id' => $context['quality_control_result_id'],
            'unique_result_id' => $context['quality_control_result_id'],
            'description' => $validated['description'],
            'criterion' => $validated['criterion'] ?? null,
            'severity' => $severity->value,
            'initial_severity' => $severity->value,
            'category' => $category->value,
            'status' => NonConformityStatus::Open->value,
            'is_blocking' => $snapshot['blocking'],
            'verification_required' => $snapshot['verification_required'],
            'counter_control_required' => $snapshot['counter_control_required'],
            'severity_policy_snapshot' => $snapshot,
            'current_severity_policy_snapshot' => $snapshot,
            'detected_at' => $detectedAt,
            'detected_by' => $actor->getKey(),
            'created_by' => $actor->getKey(),
            'responsible_id' => $validated['responsible_id'] ?? null,
            'due_date' => $dueDate,
            'treatment_plan' => $validated['treatment_plan'] ?? null,
            'immediate_action' => $validated['immediate_action'] ?? null,
            'containment_at' => $validated['containment_at'] ?? null,
            'root_cause' => $validated['root_cause'] ?? null,
            'root_cause_method' => $validated['root_cause_method'] ?? null,
            'verification_due_at' => $snapshot['verification_required']
                ? Carbon::instance($detectedAt)->addDays((int) $snapshot['due_days'])
                : null,
        ])->save();

        $this->recordHistory(
            $nonConformity,
            null,
            NonConformityStatus::Open,
            $actor,
            'Création de la non-conformité.',
            [
                'origin' => $context['origin']->value,
                'work_order_quality_control_id' => $context['work_order_quality_control_id'],
                'quality_control_result_id' => $context['quality_control_result_id'],
            ],
        );

        $this->audit->record(
            QualityAuditEvent::NonConformityCreated,
            QualityAuditSubject::NonConformity,
            (int) $nonConformity->getKey(),
            $nonConformity->reference,
            $actor,
            $workOrder,
            null,
            NonConformityStatus::Open->value,
            'Création d\'une non-conformité.',
            [
                'origin' => $context['origin']->value,
                'severity' => $severity->value,
                'category' => $category->value,
                'work_order_quality_control_id' => $context['work_order_quality_control_id'],
                'quality_control_result_id' => $context['quality_control_result_id'],
            ],
        );

        $this->audit->record(
            QualityAuditEvent::SeverityPolicyApplied,
            QualityAuditSubject::NonConformity,
            (int) $nonConformity->getKey(),
            $nonConformity->reference,
            $actor,
            $workOrder,
            null,
            null,
            'Politique de gravité figée à la création.',
            ['policy' => $snapshot],
        );

        return $nonConformity->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordHistory(
        NonConformity $nonConformity,
        ?NonConformityStatus $from,
        NonConformityStatus $to,
        ?User $actor,
        ?string $comment = null,
        array $metadata = [],
    ): NonConformityStatusHistory {
        $history = new NonConformityStatusHistory;
        $history->forceFill([
            'non_conformity_id' => $nonConformity->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->getKey(),
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ])->save();

        return $history->refresh();
    }

    protected function assertResultHasNoNonConformity(QualityControlResult $result): void
    {
        if (NonConformity::query()
            ->where('quality_control_result_id', $result->getKey())
            ->where('status', '!=', NonConformityStatus::Cancelled->value)
            ->exists()) {
            $this->fail('result', 'Une non-conformité active existe déjà pour ce résultat de contrôle.');
        }
    }

    protected function authorizeCreate(User $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('create', NonConformity::class)) {
            $this->fail('non_conformity', 'Vous n\'êtes pas autorisé à déclarer une non-conformité.');
        }
    }

    protected function authorizeWorkOrderAccess(WorkOrder $workOrder, User $actor): void
    {
        if (! $workOrder->isAccessibleBy($actor)) {
            $this->fail(
                'work_order',
                'Cet ordre de fabrication n\'est pas accessible avec vos habilitations.',
            );
        }
    }

    protected function lock(NonConformity $nonConformity): NonConformity
    {
        $locked = NonConformity::query()->lockForUpdate()->find($nonConformity->getKey());

        if ($locked === null) {
            $this->fail('non_conformity', 'Cette non-conformité n\'existe plus.');
        }

        return $locked;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
