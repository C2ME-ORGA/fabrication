<?php

namespace App\Services;

use App\DTO\QualityControlSnapshot;
use App\DTO\SamplingPlan;
use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlStatus;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Génération du plan qualité d'un ordre de fabrication.
 *
 * Chaque exigence de gamme produit un contrôle indépendant, avec une copie
 * complète des points de la version active et un plan d'échantillonnage figé.
 * Le service est idempotent : une exigence déjà copiée n'est jamais dupliquée.
 */
class QualityControlPlanGenerationService
{
    public function __construct(
        protected QualityControlSamplingService $sampling,
        protected QualityAuditService $audit,
    ) {}

    /**
     * @return Collection<int, WorkOrderQualityControl>
     */
    public function generateForWorkOrder(WorkOrder $workOrder, ?User $actor = null): Collection
    {
        $operations = $workOrder->operations()
            ->whereNotNull('source_routing_operation_id')
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var array<int, WorkOrderOperation> $operationBySource */
        $operationBySource = $operations->keyBy('source_routing_operation_id')->all();
        $sourceOperationIds = array_keys($operationBySource);

        if ($sourceOperationIds === []) {
            return new Collection;
        }

        $items = $workOrder->items()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $itemIds = $items->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $requirements = WorkshopRoutingOperationQualityRequirement::query()
            ->whereIn('workshop_routing_operation_id', $sourceOperationIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (WorkshopRoutingOperationQualityRequirement $requirement): string => sprintf(
                '%08d-%08d',
                (int) ($operationBySource[(int) $requirement->workshop_routing_operation_id]->position ?? 0),
                (int) $requirement->position,
            ))
            ->values();

        if ($requirements->isEmpty()) {
            return new Collection;
        }

        $existingRequirementIds = WorkOrderQualityControl::query()
            ->where('work_order_id', $workOrder->getKey())
            ->whereIn('source_requirement_id', $requirements->pluck('id')->all())
            ->pluck('source_requirement_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        /** @var Collection<int, WorkOrderQualityControl> $created */
        $created = new Collection;

        foreach ($requirements as $index => $requirement) {
            if (in_array((int) $requirement->getKey(), $existingRequirementIds, true)) {
                continue;
            }

            $operation = $operationBySource[(int) $requirement->workshop_routing_operation_id] ?? null;

            if ($operation === null) {
                $this->fail(
                    'quality_control',
                    "L'opération source de l'exigence qualité {$requirement->getKey()} n'a pas été copiée dans l'OF.",
                );
            }

            $control = $this->generateFromRequirement(
                $workOrder,
                $operation,
                $requirement,
                $itemIds,
                ($index + 1) * 10,
                $actor,
            );

            $created->push($control);
        }

        $this->assertGenerationIntegrity($workOrder, $requirements, $created);

        return $created;
    }

    /**
     * Ajout manuel d'un modèle à un OF brouillon ou prêt.
     *
     * @param  array<string, mixed>  $data
     */
    public function generateManualControl(
        WorkOrder $workOrder,
        QualityControlTemplate $template,
        array $data,
        User $actor,
    ): WorkOrderQualityControl {
        $version = $this->resolveActiveVersion($template);

        $scope = QualityRequirementScope::from((string) ($data['scope'] ?? QualityRequirementScope::Operation->value));
        $trigger = QualityRequirementTrigger::from((string) ($data['trigger'] ?? QualityRequirementTrigger::OnCompletion->value));

        $operation = null;

        if (isset($data['work_order_operation_id'])) {
            $operation = $workOrder->operations()
                ->whereKey($data['work_order_operation_id'])
                ->first();

            if ($operation === null) {
                $this->fail('work_order_operation_id', "L'opération sélectionnée n'appartient pas à cet OF.");
            }
        }

        $items = $workOrder->items()->orderBy('id')->lockForUpdate()->get();
        $itemIds = $items->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $position = ((int) $workOrder->qualityControls()->max('position')) + 10;

        $control = $this->createControl(
            $workOrder,
            $operation,
            $version,
            new QualityControlSnapshot(
                templateCode: $template->code,
                templateName: $template->name,
                templateVersionNumber: (int) $version->version_number,
                controlType: $template->control_type,
                scope: $scope,
                trigger: $trigger,
                isMandatory: (bool) ($data['is_mandatory'] ?? true),
                allowDeviation: false,
            ),
            sourceRequirement: null,
            sourceType: 'manual',
            position: $position,
            itemIds: $itemIds,
            actor: $actor,
            auditEvent: QualityAuditEvent::ControlAddedManually,
        );

        return $control;
    }

    protected function generateFromRequirement(
        WorkOrder $workOrder,
        WorkOrderOperation $operation,
        WorkshopRoutingOperationQualityRequirement $requirement,
        array $itemIds,
        int $position,
        ?User $actor,
    ): WorkOrderQualityControl {
        $template = QualityControlTemplate::query()
            ->lockForUpdate()
            ->find($requirement->quality_control_template_id);

        if ($template === null) {
            $this->fail('quality_control_template_id', 'Le modèle de contrôle exigé est introuvable.');
        }

        $version = $this->resolveActiveVersion($template);

        $points = $version->points()->get();

        if ($points->isEmpty()) {
            $this->fail('quality_control', "La version active du modèle {$template->code} ne contient aucun point.");
        }

        $allowDeviation = $points->contains(
            fn (QualityControlPoint $point): bool => (bool) $point->allow_deviation,
        );

        return $this->createControl(
            $workOrder,
            $operation,
            $version,
            new QualityControlSnapshot(
                templateCode: $template->code,
                templateName: $template->name,
                templateVersionNumber: (int) $version->version_number,
                controlType: $template->control_type,
                scope: $requirement->scope,
                trigger: $requirement->trigger,
                isMandatory: (bool) $requirement->is_mandatory,
                allowDeviation: $allowDeviation,
            ),
            sourceRequirement: $requirement,
            sourceType: 'routing',
            position: $position,
            itemIds: $itemIds,
            actor: $actor,
            auditEvent: QualityAuditEvent::ControlGenerated,
        );
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    protected function createControl(
        WorkOrder $workOrder,
        ?WorkOrderOperation $operation,
        QualityControlTemplateVersion $version,
        QualityControlSnapshot $snapshot,
        ?WorkshopRoutingOperationQualityRequirement $sourceRequirement,
        string $sourceType,
        int $position,
        array $itemIds,
        ?User $actor,
        QualityAuditEvent $auditEvent,
    ): WorkOrderQualityControl {
        $points = $version->points()->get();

        if ($points->isEmpty()) {
            $this->fail('quality_control', 'Un contrôle qualité doit contenir au moins un point.');
        }

        $plans = [];

        foreach ($points as $point) {
            if ($snapshot->scope !== QualityRequirementScope::Item
                && $point->sampling_mode === QualityControlSamplingMode::EveryN) {
                $this->fail(
                    'sampling_mode',
                    "Le point « {$point->label} » utilise un échantillonnage par fréquence, incompatible avec la portée « {$snapshot->scope->getLabel()} ».",
                );
            }

            $plans[(int) $point->getKey()] = $this->sampling->plan(
                $point->sampling_mode,
                $point->sampling_every,
                (int) $point->required_results,
                $itemIds,
                $snapshot->scope === QualityRequirementScope::Item,
            );
        }

        $control = new WorkOrderQualityControl;
        $control->forceFill([
            'work_order_id' => $workOrder->getKey(),
            'work_order_operation_id' => $operation?->getKey(),
            'work_order_item_id' => null,
            'source_requirement_id' => $sourceRequirement?->getKey(),
            'source_type' => $sourceType,
            ...$snapshot->toArray(),
            'allow_deviation' => $points->contains(
                fn (QualityControlPoint $point): bool => (bool) $point->allow_deviation,
            ),
            'position' => $position,
            'status' => QualityControlStatus::Pending->value,
            'required_by_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'snapshot_metadata' => [
                'template_id' => $version->quality_control_template_id,
                'template_version_id' => $version->getKey(),
                'requirement_id' => $sourceRequirement?->getKey(),
                'workshop_routing_id' => $workOrder->workshop_routing_id,
                'operation_code' => $operation?->code,
                'generated_at' => now()->toIso8601String(),
                'generator_version' => '1.0',
                'points_count' => $points->count(),
                'sampling' => collect($plans)->map(fn (SamplingPlan $plan): array => [
                    'mode' => $plan->mode->value,
                    'sampling_every' => $plan->samplingEvery,
                    'required_results' => $plan->requiredResults,
                    'selected_item_ids' => $plan->selectedItemIds,
                ])->all(),
                'points_fingerprint' => hash('sha256', json_encode($points->map(
                    fn (QualityControlPoint $point): array => [
                        'label' => $point->label,
                        'result_type' => $point->result_type->value,
                        'is_mandatory' => (bool) $point->is_mandatory,
                        'is_critical' => (bool) $point->is_critical,
                        'sampling_mode' => $point->sampling_mode->value,
                        'sampling_every' => $point->sampling_every,
                    ],
                )->all()) ?: ''),
            ],
        ])->save();

        foreach ($points as $point) {
            /** @var SamplingPlan $plan */
            $plan = $plans[(int) $point->getKey()];

            $generatedPoint = new WorkOrderQualityControlPoint;
            $generatedPoint->forceFill([
                'work_order_quality_control_id' => $control->getKey(),
                'position' => (int) $point->position,
                'label' => $point->label,
                'instructions' => $point->instructions,
                'result_type' => $point->result_type->value,
                'unit' => $point->unit,
                'tolerance_min' => $point->tolerance_min,
                'tolerance_max' => $point->tolerance_max,
                'is_mandatory' => (bool) $point->is_mandatory,
                'is_critical' => (bool) $point->is_critical,
                'allow_deviation' => (bool) $point->allow_deviation,
                'sampling_mode' => $point->sampling_mode->value,
                'sampling_every' => $point->sampling_every,
                'required_results' => $plan->requiredResults,
                'sampled_item_ids' => $plan->selectedItemIds === [] ? null : $plan->selectedItemIds,
                'options' => $point->options,
            ])->save();
        }

        $control->refresh();

        $this->audit->record(
            $auditEvent,
            QualityAuditSubject::Control,
            (int) $control->getKey(),
            $control->workOrder?->number.' / '.$snapshot->templateCode,
            $actor,
            $workOrder,
            null,
            $control->status->value,
            null,
            [
                'template_code' => $snapshot->templateCode,
                'template_version_number' => $snapshot->templateVersionNumber,
                'requirement_id' => $sourceRequirement?->getKey(),
                'scope' => $snapshot->scope->value,
                'trigger' => $snapshot->trigger->value,
                'points_count' => $points->count(),
                'sampling' => $control->snapshot_metadata['sampling'] ?? [],
            ],
        );

        return $control;
    }

    protected function resolveActiveVersion(QualityControlTemplate $template): QualityControlTemplateVersion
    {
        $version = QualityControlTemplateVersion::query()
            ->where('quality_control_template_id', $template->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($version === null) {
            $this->fail(
                'quality_control_template_id',
                "Le modèle {$template->code} ne possède aucune version active : le plan qualité ne peut pas être généré.",
            );
        }

        return $version;
    }

    /**
     * @param  Collection<int, WorkshopRoutingOperationQualityRequirement>  $requirements
     * @param  Collection<int, WorkOrderQualityControl>  $created
     */
    protected function assertGenerationIntegrity(
        WorkOrder $workOrder,
        Collection $requirements,
        Collection $created,
    ): void {
        foreach ($created as $control) {
            if ((int) $control->work_order_id !== (int) $workOrder->getKey()) {
                $this->fail('quality_control', 'Un contrôle généré n\'appartient pas à cet ordre de fabrication.');
            }

            $metadata = $control->snapshot_metadata ?? [];
            $versionId = $metadata['template_version_id'] ?? null;

            if ($versionId === null) {
                $this->fail('quality_control', 'La photographie de la version du modèle est incomplète.');
            }

            $version = QualityControlTemplateVersion::query()->find($versionId);

            if ($version === null) {
                $this->fail('quality_control', 'La version du modèle copiée est introuvable.');
            }

            $sourcePoints = $version->points()->get();
            $generatedPoints = $control->points()->get();

            if ($sourcePoints->count() !== $generatedPoints->count()) {
                $this->fail('quality_control', 'La copie des points du contrôle est incomplète.');
            }

            $generatedByPosition = $generatedPoints->keyBy('position');

            foreach ($sourcePoints as $sourcePoint) {
                $generated = $generatedByPosition->get((int) $sourcePoint->position);

                if (! $generated instanceof WorkOrderQualityControlPoint) {
                    $this->fail(
                        'quality_control',
                        "Le point « {$sourcePoint->label} » n'a pas été copié dans le contrôle généré.",
                    );
                }

                if ((string) $generated->label !== (string) $sourcePoint->label
                    || $generated->result_type !== $sourcePoint->result_type
                    || (bool) $generated->is_mandatory !== (bool) $sourcePoint->is_mandatory
                    || (bool) $generated->is_critical !== (bool) $sourcePoint->is_critical
                    || (bool) $generated->allow_deviation !== (bool) $sourcePoint->allow_deviation
                    || $generated->sampling_mode !== $sourcePoint->sampling_mode
                    || (int) ($generated->sampling_every ?? 0) !== (int) ($sourcePoint->sampling_every ?? 0)
                    || (string) ($generated->unit ?? '') !== (string) ($sourcePoint->unit ?? '')) {
                    $this->fail(
                        'quality_control',
                        "La copie du point « {$sourcePoint->label} » est incohérente.",
                    );
                }
            }
        }
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
