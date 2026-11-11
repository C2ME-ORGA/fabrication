<?php

namespace App\Services;

use App\DTO\ClosureBlocker;
use App\DTO\ClosureEligibility;
use App\DTO\QualityControlProgress;
use App\Enums\ClosureBlockerCode;
use App\Enums\CorrectiveActionStatus;
use App\Enums\NonConformityStatus;
use App\Enums\QualityControlStatus;
use App\Enums\QualityControlType;
use App\Enums\QualityDeviationStatus;
use App\Enums\QualityRequirementScope;
use App\Models\NonConformity;
use App\Models\QualityDeviation;
use App\Models\QualityDeviationTarget;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use Illuminate\Support\Collection;

/**
 * Calcul centralisé de l'éligibilité qualité d'un ordre de fabrication.
 *
 * La phase `release` vérifie l'intégrité du plan qualité et les blocages
 * déjà connus. La phase `closure` vérifie en plus les résultats validés, les
 * points critiques, les non-conformités et les dérogations.
 */
class QualityClosureEligibilityService
{
    public const PHASE_RELEASE = 'release';

    public const PHASE_CLOSURE = 'closure';

    /**
     * Revue explicite d'un OF déjà figé (terminé) afin de produire une preuve
     * de libération qualité sans réactiver l'OF.
     */
    public const PHASE_HISTORICAL_REVIEW = 'historical_review';

    /**
     * Réévaluation qualité au moment de la validation d'une expédition.
     * Même matrice que la clôture, sans le court-circuit `isLocked()`.
     */
    public const PHASE_SHIPMENT = 'shipment';

    public function __construct(
        protected QualityDeviationService $deviations,
        protected QualityControlEvaluationService $evaluation,
    ) {}

    public function evaluateForShipment(WorkOrder $workOrder): ClosureEligibility
    {
        return $this->evaluate($workOrder, self::PHASE_SHIPMENT, ignoreLocked: true);
    }

    public function evaluate(
        WorkOrder $workOrder,
        string $phase = self::PHASE_CLOSURE,
        bool $ignoreLocked = false,
    ): ClosureEligibility {
        $this->deviations->expireOverdueForWorkOrder($workOrder);

        $workOrder = WorkOrder::query()->find($workOrder->getKey()) ?? $workOrder;

        $blockers = [];
        $warnings = [];
        $controlIds = [];
        $nonConformityIds = [];
        $deviationIds = [];

        if ($workOrder->isLocked() && ! $ignoreLocked) {
            return new ClosureEligibility(
                eligible: true,
                blockers: [],
                warnings: [$workOrder->status->value === 'cancelled'
                    ? 'OF annulé : le verrou qualité est désactivé.'
                    : 'OF terminé : calcul d\'éligibilité informatif.'],
                controlIds: [],
                nonConformityIds: [],
                deviationIds: [],
                evaluatedAt: now()->toIso8601String(),
                phase: $phase,
            );
        }

        $deviations = QualityDeviation::query()
            ->where('work_order_id', $workOrder->getKey())
            ->where('status', QualityDeviationStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with('targets')
            ->orderBy('id')
            ->get();

        $controlCoverage = [];
        $pointCoverage = [];
        $nonConformityCoverage = [];

        foreach ($deviations as $deviation) {
            foreach ($deviation->targets as $target) {
                if ($target->work_order_quality_control_id !== null) {
                    $controlCoverage[(int) $target->work_order_quality_control_id] = (int) $deviation->getKey();
                }

                if ($target->work_order_quality_control_point_id !== null) {
                    $pointCoverage[(int) $target->work_order_quality_control_point_id] = (int) $deviation->getKey();
                }

                if ($target->non_conformity_id !== null) {
                    $nonConformityCoverage[(int) $target->non_conformity_id] = (int) $deviation->getKey();
                }
            }
        }

        $controls = WorkOrderQualityControl::query()
            ->where('work_order_id', $workOrder->getKey())
            ->with('points.results.validations')
            ->orderBy('id')
            ->get();

        $this->assertMandatoryRequirementsGenerated($workOrder, $controls, $blockers);

        foreach ($controls as $control) {
            if (! $control->is_mandatory || $control->status === QualityControlStatus::Cancelled) {
                continue;
            }

            $controlIds[] = (int) $control->getKey();

            if ($phase === self::PHASE_RELEASE) {
                continue;
            }

            if ($this->isFinalDocumentaryControl($control)) {
                if ($control->status !== QualityControlStatus::Passed) {
                    $blockers[] = new ClosureBlocker(
                        ClosureBlockerCode::MissingFinalDocumentaryControl,
                        "Le contrôle final documentaire « {$control->template_name} » n'est pas conforme.",
                        controlId: (int) $control->getKey(),
                    );
                }

                continue;
            }

            $progress = $this->evaluation->progress($control);
            $controlDeviationId = $controlCoverage[(int) $control->getKey()] ?? null;

            foreach ($control->points as $point) {
                if (! $point->is_mandatory) {
                    continue;
                }

                /** @var QualityControlProgress|null $pointProgress */
                $pointProgress = $progress->firstWhere('pointId', (int) $point->getKey());

                if ($pointProgress?->satisfied === true) {
                    continue;
                }

                $deviationId = $pointCoverage[(int) $point->getKey()] ?? $controlDeviationId;

                // Une dérogation ne peut couvrir qu'un constat non conforme
                // déjà enregistré, jamais une absence de résultat ni une
                // validation obligatoire manquante.
                if ($deviationId !== null && $pointProgress?->hasNonConformity === true) {
                    $deviationIds[] = $deviationId;

                    continue;
                }

                if ($pointProgress?->hasNonConformity === true) {
                    $blockers[] = new ClosureBlocker(
                        ClosureBlockerCode::NonConformingMandatoryControl,
                        "Le point obligatoire « {$point->label} » du contrôle « {$control->template_name} » est non conforme.",
                        controlId: (int) $control->getKey(),
                        pointId: (int) $point->getKey(),
                    );

                    continue;
                }

                if ($pointProgress?->hasPendingValidation === true) {
                    $blockers[] = new ClosureBlocker(
                        ClosureBlockerCode::UnvalidatedMandatoryResult,
                        "Le point obligatoire « {$point->label} » du contrôle « {$control->template_name} » attend une validation.",
                        controlId: (int) $control->getKey(),
                        pointId: (int) $point->getKey(),
                    );

                    continue;
                }

                $blockers[] = new ClosureBlocker(
                    ClosureBlockerCode::MissingMandatoryControl,
                    "Le point obligatoire « {$point->label} » du contrôle « {$control->template_name} » n'a pas atteint les résultats validés attendus.",
                    controlId: (int) $control->getKey(),
                    pointId: (int) $point->getKey(),
                );
            }

            if ($control->status === QualityControlStatus::Failed && ! $this->hasCoveredNonConformity($control, $pointCoverage, $controlDeviationId)) {
                // Les points ont normalement déjà produit un blocage plus
                // précis. Ce garde-fou couvre un contrôle incohérent sans
                // point obligatoire exploitable.
                if (! collect($blockers)->contains(fn (ClosureBlocker $blocker): bool => $blocker->controlId === (int) $control->getKey())) {
                    $blockers[] = new ClosureBlocker(
                        ClosureBlockerCode::NonConformingMandatoryControl,
                        "Le contrôle obligatoire « {$control->template_name} » est en échec.",
                        controlId: (int) $control->getKey(),
                    );
                }
            }
        }

        $nonConformities = NonConformity::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereNotIn('status', [
                NonConformityStatus::Closed->value,
                NonConformityStatus::Cancelled->value,
            ])
            ->with('correctiveActions')
            ->orderBy('id')
            ->get();

        foreach ($nonConformities as $nonConformity) {
            $nonConformityIds[] = (int) $nonConformity->getKey();

            if ($nonConformity->status === NonConformityStatus::PendingVerification
                && $nonConformity->verification_required
                && (bool) config('quality.closure.block_on_pending_verification', true)) {
                $blockers[] = new ClosureBlocker(
                    ClosureBlockerCode::PendingVerificationNonConformity,
                    "La non-conformité {$nonConformity->reference} est en attente de vérification.",
                    nonConformityId: (int) $nonConformity->getKey(),
                );

                continue;
            }

            if ($nonConformity->correctiveActions->contains(
                fn ($action): bool => $action->status === CorrectiveActionStatus::Ineffective,
            )) {
                $blockers[] = new ClosureBlocker(
                    ClosureBlockerCode::IneffectiveCorrectiveAction,
                    "La non-conformité {$nonConformity->reference} possède une action corrective inefficace.",
                    nonConformityId: (int) $nonConformity->getKey(),
                );

                continue;
            }

            if ($nonConformity->is_blocking
                && in_array($nonConformity->status, [
                    NonConformityStatus::Open,
                    NonConformityStatus::InProgress,
                ], true)) {
                $deviationId = $nonConformityCoverage[(int) $nonConformity->getKey()] ?? null;

                if ($deviationId !== null) {
                    $deviationIds[] = $deviationId;

                    continue;
                }

                $blockers[] = new ClosureBlocker(
                    ClosureBlockerCode::OpenBlockingNonConformity,
                    "La non-conformité bloquante {$nonConformity->reference} n'est pas clôturée.",
                    nonConformityId: (int) $nonConformity->getKey(),
                );
            }
        }

        $inactiveDeviations = QualityDeviation::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereIn('status', [
                QualityDeviationStatus::Revoked->value,
                QualityDeviationStatus::Expired->value,
                QualityDeviationStatus::Rejected->value,
            ])
            ->orderBy('id')
            ->pluck('reference')
            ->all();

        if ($inactiveDeviations !== []) {
            $warnings[] = 'Dérogations non actives ignorées : '.implode(', ', $inactiveDeviations).'.';
        }

        return new ClosureEligibility(
            eligible: $blockers === [],
            blockers: $blockers,
            warnings: $warnings,
            controlIds: array_values(array_unique($controlIds)),
            nonConformityIds: array_values(array_unique($nonConformityIds)),
            deviationIds: array_values(array_unique($deviationIds)),
            evaluatedAt: now()->toIso8601String(),
            phase: $phase,
        );
    }

    /**
     * @param  Collection<int, QualityDeviationTarget>  $targets
     */
    protected function hasCoveredNonConformity(
        WorkOrderQualityControl $control,
        array $pointCoverage,
        ?int $controlDeviationId,
    ): bool {
        foreach ($control->points as $point) {
            if (! $point->is_mandatory) {
                continue;
            }

            $hasNonConformity = $point->results->contains(
                fn ($result): bool => $result->is_conform === false && ! $result->isRejected(),
            );

            if (! $hasNonConformity) {
                continue;
            }

            if (($pointCoverage[(int) $point->getKey()] ?? $controlDeviationId) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indique si l'OF portait au moins une exigence qualité obligatoire.
     *
     * Utilisé pour renseigner `requirements_present` dans la preuve : un OF
     * sans exigence reste éligible (compatibilité FC-6/FC-7), mais la preuve
     * doit le dire explicitement.
     */
    public function hasMandatoryRequirements(WorkOrder $workOrder): bool
    {
        if (WorkOrderQualityControl::query()
            ->where('work_order_id', $workOrder->getKey())
            ->where('is_mandatory', true)
            ->exists()) {
            return true;
        }

        $sourceOperationIds = $workOrder->operations()
            ->whereNotNull('source_routing_operation_id')
            ->pluck('source_routing_operation_id')
            ->all();

        if ($sourceOperationIds === []) {
            return false;
        }

        return WorkshopRoutingOperationQualityRequirement::query()
            ->whereIn('workshop_routing_operation_id', $sourceOperationIds)
            ->where('is_mandatory', true)
            ->exists();
    }

    /**
     * @param  Collection<int, WorkOrderQualityControl>  $controls
     * @param  array<int, ClosureBlocker>  $blockers
     */
    protected function assertMandatoryRequirementsGenerated(
        WorkOrder $workOrder,
        Collection $controls,
        array &$blockers,
    ): void {
        $sourceOperationIds = $workOrder->operations()
            ->whereNotNull('source_routing_operation_id')
            ->pluck('source_routing_operation_id')
            ->all();

        if ($sourceOperationIds === []) {
            return;
        }

        $requirements = WorkshopRoutingOperationQualityRequirement::query()
            ->whereIn('workshop_routing_operation_id', $sourceOperationIds)
            ->where('is_mandatory', true)
            ->orderBy('id')
            ->get();

        if ($requirements->isEmpty()) {
            return;
        }

        $generatedRequirementIds = $controls
            ->pluck('source_requirement_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($requirements as $requirement) {
            if (! in_array((int) $requirement->getKey(), $generatedRequirementIds, true)) {
                $blockers[] = new ClosureBlocker(
                    ClosureBlockerCode::MissingMandatoryControl,
                    "L'exigence qualité obligatoire #{$requirement->getKey()} n'a généré aucun contrôle dans l'OF.",
                    metadata: ['requirement_id' => (int) $requirement->getKey()],
                );
            }
        }
    }

    protected function isFinalDocumentaryControl(WorkOrderQualityControl $control): bool
    {
        return $control->control_type === QualityControlType::Final
            && $control->scope === QualityRequirementScope::WorkOrder;
    }
}
