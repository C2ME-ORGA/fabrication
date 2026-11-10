<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opérations sur le plan qualité d'un OF en dehors de la génération initiale :
 * ajout manuel avant libération, annulation d'un contrôle et recalcul encadré
 * de l'échantillonnage après modification des repères.
 */
class WorkOrderQualityPlanService
{
    public function __construct(
        protected WorkOrderService $workOrders,
        protected QualityControlPlanGenerationService $generation,
        protected QualityControlSamplingService $sampling,
        protected QualityControlEvaluationService $evaluation,
        protected QualityAuditService $audit,
        protected Gate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function addTemplate(
        WorkOrder $workOrder,
        QualityControlTemplate $template,
        array $data,
        User $actor,
    ): WorkOrderQualityControl {
        if (! $actor->canManageQualityTemplates()) {
            $this->fail('quality_control', 'Vous n\'êtes pas autorisé à ajouter un modèle de contrôle à cet OF.');
        }

        return DB::transaction(function () use ($workOrder, $template, $data, $actor): WorkOrderQualityControl {
            $locked = $this->workOrders->lockForMutation($workOrder);

            if (! $locked->isRoutingEditable()) {
                $this->fail(
                    'quality_control',
                    "Le plan qualité de l'ordre de fabrication {$locked->number} est figé après sa libération.",
                );
            }

            return $this->generation->generateManualControl($locked, $template, $data, $actor);
        }, attempts: 3);
    }

    /**
     * Marque exigibles les contrôles `on_completion` d'une opération terminée.
     *
     * Seul le déclencheur `on_completion` est concerné. Les contrôles déjà
     * clôturés ou annulés sont ignorés. L'échéance est indicative : le
     * verrou de clôture relève du Lot 5.
     */
    public function markControlsRequiredOnOperationCompletion(
        WorkOrderOperation $operation,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($operation, $actor): void {
            $workOrder = WorkOrder::query()->lockForUpdate()->find($operation->work_order_id);

            if ($workOrder === null) {
                return;
            }

            $controls = WorkOrderQualityControl::query()
                ->where('work_order_id', $workOrder->getKey())
                ->where('work_order_operation_id', $operation->getKey())
                ->where('trigger', QualityRequirementTrigger::OnCompletion->value)
                ->whereNull('required_by_at')
                ->whereNotIn('status', [
                    QualityControlStatus::Passed->value,
                    QualityControlStatus::Waived->value,
                    QualityControlStatus::Cancelled->value,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $requiredAt = now();

            foreach ($controls as $control) {
                $status = $control->status;

                $control->forceFill(['required_by_at' => $requiredAt])->save();

                $this->audit->record(
                    QualityAuditEvent::ControlRequired,
                    QualityAuditSubject::Control,
                    (int) $control->getKey(),
                    $this->evaluation->controlReference($control),
                    $actor,
                    $workOrder,
                    $status->value,
                    $status->value,
                    'Le contrôle est devenu exigible à la terminaison de l\'opération.',
                    [
                        'trigger' => QualityRequirementTrigger::OnCompletion->value,
                        'operation_id' => $operation->getKey(),
                        'operation_code' => $operation->code,
                        'required_by_at' => $requiredAt->toIso8601String(),
                    ],
                );
            }
        }, attempts: 3);
    }

    /**
     * Supprime physiquement un contrôle ajouté manuellement, avant libération
     * de l'OF et uniquement s'il ne porte aucun résultat. Après libération,
     * seule l'annulation tracée est autorisée.
     */
    public function deleteManualControl(WorkOrderQualityControl $control, User $actor): void
    {
        if (! $actor->canManageQualityTemplates()) {
            $this->fail('quality_control', 'Vous n\'êtes pas autorisé à supprimer un contrôle de cet OF.');
        }

        DB::transaction(function () use ($control, $actor): void {
            $workOrder = WorkOrder::query()->lockForUpdate()->find($control->work_order_id);

            if ($workOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            if (! $workOrder->isRoutingEditable()) {
                $this->fail(
                    'quality_control',
                    "Le plan qualité de l'ordre de fabrication {$workOrder->number} est figé après sa libération : utilisez l'annulation tracée.",
                );
            }

            $locked = WorkOrderQualityControl::query()
                ->where('work_order_id', $workOrder->getKey())
                ->whereKey($control->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                $this->fail('quality_control', 'Ce contrôle n\'appartient pas à cet ordre de fabrication.');
            }

            if ($locked->source_type !== 'manual' || $locked->source_requirement_id !== null) {
                $this->fail(
                    'quality_control',
                    'Seul un contrôle ajouté manuellement peut être supprimé. Un contrôle généré depuis une gamme doit être annulé.',
                );
            }

            if ($locked->hasResults()) {
                $this->fail(
                    'quality_control',
                    'Un contrôle portant des résultats ne peut pas être supprimé. Utilisez l\'annulation.',
                );
            }

            $reference = $this->evaluation->controlReference($locked);
            $metadata = [
                'template_code' => $locked->template_code,
                'template_version_number' => $locked->template_version_number,
                'scope' => $locked->scope->value,
                'trigger' => $locked->trigger->value,
                'status' => $locked->status->value,
                'source_type' => $locked->source_type,
            ];

            $this->audit->record(
                QualityAuditEvent::ControlDeleted,
                QualityAuditSubject::Control,
                (int) $locked->getKey(),
                $reference,
                $actor,
                $workOrder,
                $locked->status->value,
                null,
                'Suppression d\'un contrôle ajouté manuellement avant libération.',
                $metadata,
            );

            $locked->delete();
        }, attempts: 3);
    }

    public function cancel(
        WorkOrderQualityControl $control,
        User $actor,
        string $reason,
    ): WorkOrderQualityControl {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason', 'Le motif d\'annulation du contrôle est obligatoire.');
        }

        if (mb_strlen($reason) > 2000) {
            $this->fail('reason', 'Le motif d\'annulation ne peut pas dépasser 2 000 caractères.');
        }

        return DB::transaction(function () use ($control, $actor, $reason): WorkOrderQualityControl {
            $workOrder = WorkOrder::query()->lockForUpdate()->find($control->work_order_id);

            if ($workOrder === null) {
                $this->fail('work_order', 'Cet ordre de fabrication n\'existe plus.');
            }

            $locked = WorkOrderQualityControl::query()->lockForUpdate()->find($control->getKey());

            if ($locked === null || (int) $locked->work_order_id !== (int) $workOrder->getKey()) {
                $this->fail('quality_control', 'Ce contrôle n\'appartient pas à cet ordre de fabrication.');
            }

            if (! $this->gate->forUser($actor)->allows('cancel', $locked)) {
                $this->fail('quality_control', 'Vous n\'êtes pas autorisé à annuler ce contrôle.');
            }

            if (! in_array($locked->status, [
                QualityControlStatus::Pending,
                QualityControlStatus::InProgress,
            ], true)) {
                $this->fail(
                    'status',
                    "Un contrôle « {$locked->status->getLabel()} » ne peut pas être annulé.",
                );
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => QualityControlStatus::Cancelled,
                'completed_at' => null,
                'completed_by' => null,
            ])->save();

            $this->audit->record(
                QualityAuditEvent::ControlCancelled,
                QualityAuditSubject::Control,
                (int) $locked->getKey(),
                $this->evaluation->controlReference($locked),
                $actor,
                $workOrder,
                $from->value,
                QualityControlStatus::Cancelled->value,
                $reason,
                ['reason' => $reason],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Recalcule les `required_results` et la sélection des repères des
     * contrôles en portée item après un ajout, une suppression ou une fusion
     * de repère. La sélection déjà persistée n'est jamais remise en cause.
     */
    public function recalculateForItemChange(WorkOrder $workOrder, ?User $actor = null): void
    {
        DB::transaction(function () use ($workOrder, $actor): void {
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->find($workOrder->getKey());

            if ($lockedWorkOrder === null) {
                return;
            }

            $controls = WorkOrderQualityControl::query()
                ->where('work_order_id', $lockedWorkOrder->getKey())
                ->where('scope', QualityRequirementScope::Item->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($controls->isEmpty()) {
                return;
            }

            $itemIds = $lockedWorkOrder->items()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($controls as $control) {
                if ($control->isCancelled()) {
                    continue;
                }

                $points = $control->points()->orderBy('id')->lockForUpdate()->get();
                $changes = [];
                $requiredIncreased = false;

                foreach ($points as $point) {
                    if (! $point->usesDeterministicSelection()) {
                        continue;
                    }

                    $beforeRequired = (int) $point->required_results;
                    $beforeSelected = $point->sampledItemIds();

                    $plan = $this->sampling->plan(
                        $point->sampling_mode,
                        $point->sampling_every,
                        (int) $point->required_results,
                        $itemIds,
                        true,
                        $beforeSelected,
                    );

                    $selected = $plan->selectedItemIds;

                    if ($beforeRequired === $plan->requiredResults && $beforeSelected === $selected) {
                        continue;
                    }

                    if ($plan->requiredResults > $beforeRequired) {
                        $requiredIncreased = true;
                    }

                    $point->forceFill([
                        'required_results' => $plan->requiredResults,
                        'sampled_item_ids' => $selected === [] ? null : $selected,
                    ])->save();

                    $changes[] = [
                        'point_id' => $point->getKey(),
                        'sampling_mode' => $point->sampling_mode->value,
                        'sampling_every' => $point->sampling_every,
                        'previous_required_results' => $beforeRequired,
                        'required_results' => $plan->requiredResults,
                        'previous_sampled_item_ids' => $beforeSelected,
                        'sampled_item_ids' => $selected,
                    ];
                }

                if ($changes === []) {
                    continue;
                }

                if ($requiredIncreased
                    && $control->status === QualityControlStatus::Passed
                    && (bool) config('quality.controls.reopen_on_item_added_after_pass', true)) {
                    $control->forceFill([
                        'status' => QualityControlStatus::InProgress,
                        'completed_at' => null,
                        'completed_by' => null,
                    ])->save();

                    $this->audit->record(
                        QualityAuditEvent::ControlStatusChanged,
                        QualityAuditSubject::Control,
                        (int) $control->getKey(),
                        $this->evaluation->controlReference($control),
                        $actor,
                        $lockedWorkOrder,
                        QualityControlStatus::Passed->value,
                        QualityControlStatus::InProgress->value,
                        'Réouverture après ajout d\'un repère.',
                        [],
                    );
                }

                $this->audit->record(
                    QualityAuditEvent::SamplingPlanRecalculated,
                    QualityAuditSubject::Control,
                    (int) $control->getKey(),
                    $this->evaluation->controlReference($control),
                    $actor,
                    $lockedWorkOrder,
                    null,
                    null,
                    'Recalcul de l\'échantillonnage après modification des repères.',
                    [
                        'items_count' => count($itemIds),
                        'changes' => $changes,
                    ],
                );

                $this->evaluation->recalculate($control->refresh(), $actor);
            }
        }, attempts: 3);
    }

    /**
     * Contrôles dont les paramètres d'échantillonnage sont calculés.
     *
     * @return array<int, string>
     */
    public function deterministicSamplingModes(): array
    {
        return [
            QualityControlSamplingMode::All->value,
            QualityControlSamplingMode::EveryN->value,
        ];
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
