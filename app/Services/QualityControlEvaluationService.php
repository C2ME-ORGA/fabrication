<?php

namespace App\Services;

use App\DTO\QualityControlProgress;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityResultDecision;
use App\Models\QualityControlResult;
use App\Models\QualityControlResultValidation;
use App\Models\User;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Illuminate\Support\Collection;

/**
 * Recalcul de l'avancement et du statut d'un contrôle généré.
 *
 * Le statut n'est jamais fourni par un formulaire : il est dérivé des
 * résultats retenus. En portée `item`, un résultat ne compte que pour un
 * repère distinct et seul le dernier résultat retenu d'un repère est pris en
 * compte. En portée `operation` ou `work_order`, les derniers résultats
 * validés sont retenus dans la limite de `required_results` : un nouveau
 * résultat validé remplace le plus ancien retenu lors d'un recontrôle. Aucun
 * résultat historique n'est supprimé ou modifié.
 *
 * Un point critique n'est retenu qu'après le nombre requis de validations
 * distinctes.
 */
class QualityControlEvaluationService
{
    public const RULE_ITEM = 'latest_validated_result_per_item';

    public const RULE_OPERATION_OR_WORK_ORDER = 'latest_validated_results_within_required_results';

    public function __construct(
        protected QualityAuditService $audit,
    ) {}

    public function recalculate(WorkOrderQualityControl $control, ?User $actor = null): WorkOrderQualityControl
    {
        $control->loadMissing('points');
        $control->points->load('results.validations');

        $from = $control->status;
        $progress = $this->progress($control);
        $hasResults = $control->points->contains(
            fn (WorkOrderQualityControlPoint $point): bool => $point->results->isNotEmpty(),
        );

        $status = $this->determineStatus($control, $progress, $hasResults);

        $attributes = ['status' => $status];

        if ($status === QualityControlStatus::Passed) {
            $attributes['completed_at'] = $control->completed_at ?? now();
            $attributes['completed_by'] = $control->completed_by ?? $actor?->getKey();
        } else {
            $attributes['completed_at'] = null;
            $attributes['completed_by'] = null;
        }

        $control->forceFill($attributes)->save();
        $control->refresh();
        $control->load('points.results.validations');

        if ($from !== $status) {
            $this->audit->record(
                QualityAuditEvent::ControlStatusChanged,
                QualityAuditSubject::Control,
                (int) $control->getKey(),
                $this->controlReference($control),
                $actor,
                $control->workOrder,
                $from->value,
                $status->value,
                null,
                [
                    'progress' => array_map(
                        fn (QualityControlProgress $item): array => $item->toArray(),
                        $progress->all(),
                    ),
                    'has_results' => $hasResults,
                    'scope' => $control->scope->value,
                    'evaluation_rule' => $this->ruleForScope($control->scope),
                    'retained_result_ids' => $this->retainedResultIds($progress),
                    'historical_results_preserved' => true,
                ],
            );
        }

        return $control;
    }

    /**
     * @return Collection<int, QualityControlProgress>
     */
    public function progress(WorkOrderQualityControl $control): Collection
    {
        $control->loadMissing('points.results.validations');

        return $control->points->map(
            fn (WorkOrderQualityControlPoint $point): QualityControlProgress => $this->pointProgress($control, $point),
        );
    }

    public function pointProgress(
        WorkOrderQualityControl $control,
        WorkOrderQualityControlPoint $point,
    ): QualityControlProgress {
        $requiredValidations = $point->requiredValidations();
        $latestRetained = [];
        $pending = false;

        if ($control->scope->value === 'item') {
            $groups = $point->results
                ->sortBy('id')
                ->groupBy(fn (QualityControlResult $result): string => 'item:'.(int) $result->work_order_item_id);

            foreach ($groups as $results) {
                $retained = null;

                foreach ($results->sortByDesc('id') as $result) {
                    /** @var QualityControlResult $result */
                    if ($this->isRejected($result)) {
                        continue;
                    }

                    if ($this->validatedUsersCount($result) >= $requiredValidations) {
                        $retained = $result;
                        break;
                    }
                }

                if ($retained === null) {
                    if ($results->isNotEmpty()) {
                        $pending = true;
                    }

                    continue;
                }

                $latestRetained[] = $retained;
            }
        } else {
            // En portée opération / OF, les résultats comptent par point et
            // non par repère. On retient les derniers résultats validés dans
            // la limite de `required_results`, ce qui permet le recontrôle :
            // un nouveau résultat validation remplace le plus ancien retenu.
            $required = max(1, (int) $point->required_results);

            foreach ($point->results->sortByDesc('id') as $result) {
                /** @var QualityControlResult $result */
                if ($this->isRejected($result)) {
                    continue;
                }

                if ($this->validatedUsersCount($result) < $requiredValidations) {
                    $pending = true;

                    continue;
                }

                $latestRetained[] = $result;

                if (count($latestRetained) >= $required) {
                    break;
                }
            }
        }

        $conforming = count(array_filter(
            $latestRetained,
            fn (QualityControlResult $result): bool => $result->is_conform === true,
        ));

        $hasNonConformity = collect($latestRetained)->contains(
            fn (QualityControlResult $result): bool => $result->is_conform === false,
        );

        $satisfied = ! $hasNonConformity
            && $conforming >= (int) $point->required_results;

        $retainedResultIds = array_map(
            fn (QualityControlResult $result): int => (int) $result->getKey(),
            $latestRetained,
        );
        sort($retainedResultIds);

        return new QualityControlProgress(
            pointId: (int) $point->getKey(),
            requiredResults: (int) $point->required_results,
            conformingResults: $conforming,
            retainedResults: count($latestRetained),
            satisfied: $satisfied,
            hasNonConformity: $hasNonConformity,
            hasPendingValidation: $pending,
            evaluationRule: $this->ruleForScope($control->scope),
            retainedResultIds: $retainedResultIds,
        );
    }

    public function ruleForScope(QualityRequirementScope $scope): string
    {
        return $scope === QualityRequirementScope::Item
            ? self::RULE_ITEM
            : self::RULE_OPERATION_OR_WORK_ORDER;
    }

    /**
     * @param  Collection<int, QualityControlProgress>  $progress
     * @return array<int, int>
     */
    protected function retainedResultIds(Collection $progress): array
    {
        return $progress
            ->flatMap(fn (QualityControlProgress $item): array => $item->retainedResultIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    protected function validatedUsersCount(QualityControlResult $result): int
    {
        return $result->validations
            ->filter(fn (QualityControlResultValidation $validation): bool => $validation->decision === QualityResultDecision::Validated)
            ->pluck('validated_by')
            ->filter()
            ->unique()
            ->count();
    }

    protected function isRejected(QualityControlResult $result): bool
    {
        return $result->validations->contains(
            fn (QualityControlResultValidation $validation): bool => $validation->decision === QualityResultDecision::Rejected,
        );
    }

    /**
     * @param  Collection<int, QualityControlProgress>  $progress
     */
    protected function determineStatus(
        WorkOrderQualityControl $control,
        Collection $progress,
        bool $hasResults,
    ): QualityControlStatus {
        if (in_array($control->status, [
            QualityControlStatus::Cancelled,
            QualityControlStatus::Waived,
        ], true)) {
            return $control->status;
        }

        if (! $hasResults) {
            return QualityControlStatus::Pending;
        }

        $mandatoryProgress = $control->points
            ->filter(fn (WorkOrderQualityControlPoint $point): bool => (bool) $point->is_mandatory)
            ->map(fn (WorkOrderQualityControlPoint $point): QualityControlProgress => $progress->firstWhere('pointId', (int) $point->getKey()))
            ->filter();

        $hasMandatoryFailure = $mandatoryProgress->contains(
            fn (QualityControlProgress $item): bool => $item->hasNonConformity,
        );

        if ($hasMandatoryFailure) {
            return QualityControlStatus::Failed;
        }

        $allMandatorySatisfied = $mandatoryProgress->every(
            fn (QualityControlProgress $item): bool => $item->satisfied,
        );

        if ($allMandatorySatisfied) {
            return QualityControlStatus::Passed;
        }

        return QualityControlStatus::InProgress;
    }

    public function controlReference(WorkOrderQualityControl $control): string
    {
        $workOrderNumber = $control->workOrder?->number ?? 'OF';

        return $workOrderNumber.' / contrôle #'.$control->getKey();
    }

    /**
     * Résumé lisible de l'avancement, utilisé par Filament.
     */
    public function summary(WorkOrderQualityControl $control): string
    {
        $progress = $this->progress($control);

        if ($progress->isEmpty()) {
            return '—';
        }

        $required = (int) $progress->sum(fn (QualityControlProgress $item): int => $item->requiredResults);
        $conforming = (int) $progress->sum(fn (QualityControlProgress $item): int => $item->conformingResults);

        return $conforming.' / '.$required;
    }
}
