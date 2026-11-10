<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des exigences qualité portées par les opérations d'une gamme.
 *
 * Une exigence référence l'identité d'un modèle, jamais une version : la
 * version active est résolue au moment de la génération du plan qualité de
 * l'OF. Une gamme dont une exigence référence un modèle inactif ne peut pas
 * être activée ni affectée à un OF.
 */
class WorkshopRoutingQualityRequirementService
{
    public function __construct(
        protected QualityAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        WorkshopRoutingOperation $operation,
        array $data,
        ?User $actor = null,
    ): WorkshopRoutingOperationQualityRequirement {
        $validated = $this->validate($data);

        return DB::transaction(function () use ($operation, $validated, $actor): WorkshopRoutingOperationQualityRequirement {
            [$routing, $lockedOperation] = $this->lockOperationContext($operation);

            $template = $this->lockTemplate((int) $validated['quality_control_template_id']);
            $this->assertTemplateUsable($template);

            $scope = QualityRequirementScope::from($validated['scope']);

            $this->assertNotDuplicated($lockedOperation, $template);
            $this->assertScopeSupportsSampling($scope, $template);

            $requirement = new WorkshopRoutingOperationQualityRequirement;
            $requirement->workshop_routing_operation_id = $lockedOperation->getKey();
            $requirement->quality_control_template_id = $template->getKey();
            $requirement->scope = $scope;
            $requirement->trigger = QualityRequirementTrigger::from($validated['trigger']);
            $requirement->is_mandatory = $validated['is_mandatory'] ?? true;
            $requirement->position = (int) ($validated['position'] ?? $this->nextPosition($lockedOperation));
            $requirement->save();

            $this->audit->record(
                QualityAuditEvent::RequirementAdded,
                QualityAuditSubject::Requirement,
                (int) $requirement->getKey(),
                $this->requirementReference($routing, $lockedOperation, $template),
                $actor,
                null,
                null,
                null,
                'Ajout d\'une exigence qualité à une opération de gamme.',
                [
                    'workshop_routing_id' => $routing->getKey(),
                    'operation_code' => $lockedOperation->code,
                    'template_code' => $template->code,
                    'scope' => $scope->value,
                    'trigger' => $requirement->trigger->value,
                    'is_mandatory' => $requirement->is_mandatory,
                ],
            );

            return $requirement->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        WorkshopRoutingOperationQualityRequirement $requirement,
        array $data,
        ?User $actor = null,
    ): WorkshopRoutingOperationQualityRequirement {
        $validated = $this->validate($data, partial: true);

        return DB::transaction(function () use ($requirement, $validated, $actor): WorkshopRoutingOperationQualityRequirement {
            [$routing, $lockedOperation] = $this->lockOperationContext($requirement->operation);

            $locked = $lockedOperation->qualityRequirements()
                ->whereKey($requirement->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                $this->fail('requirement', 'Cette exigence n\'appartient pas à cette opération.');
            }

            $template = $locked->template()->first();

            if (array_key_exists('quality_control_template_id', $validated)
                && (int) $validated['quality_control_template_id'] !== (int) $locked->quality_control_template_id) {
                $template = $this->lockTemplate((int) $validated['quality_control_template_id']);
                $this->assertNotDuplicated($lockedOperation, $template, (int) $locked->getKey());
            }

            if ($template === null) {
                $this->fail('quality_control_template_id', 'Le modèle de contrôle est introuvable.');
            }

            $this->assertTemplateUsable($template);

            $scope = array_key_exists('scope', $validated)
                ? QualityRequirementScope::from($validated['scope'])
                : $locked->scope;

            $this->assertScopeSupportsSampling($scope, $template);

            $locked->fill([
                'scope' => $scope,
                'trigger' => array_key_exists('trigger', $validated)
                    ? QualityRequirementTrigger::from($validated['trigger'])
                    : $locked->trigger,
                'is_mandatory' => $validated['is_mandatory'] ?? $locked->is_mandatory,
                'position' => $validated['position'] ?? $locked->position,
            ]);
            $locked->quality_control_template_id = $template->getKey();
            $locked->save();

            $this->audit->record(
                QualityAuditEvent::RequirementUpdated,
                QualityAuditSubject::Requirement,
                (int) $locked->getKey(),
                $this->requirementReference($routing, $lockedOperation, $template),
                $actor,
                null,
                null,
                null,
                'Modification d\'une exigence qualité.',
                [
                    'workshop_routing_id' => $routing->getKey(),
                    'operation_code' => $lockedOperation->code,
                    'template_code' => $template->code,
                    'scope' => $scope->value,
                    'trigger' => $locked->trigger->value,
                    'is_mandatory' => $locked->is_mandatory,
                ],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    public function delete(
        WorkshopRoutingOperationQualityRequirement $requirement,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($requirement, $actor): void {
            [$routing, $lockedOperation] = $this->lockOperationContext($requirement->operation);

            $locked = $lockedOperation->qualityRequirements()
                ->whereKey($requirement->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                $this->fail('requirement', 'Cette exigence n\'appartient pas à cette opération.');
            }

            $template = $locked->template()->first();

            $this->audit->record(
                QualityAuditEvent::RequirementRemoved,
                QualityAuditSubject::Requirement,
                (int) $locked->getKey(),
                $template === null ? null : $this->requirementReference($routing, $lockedOperation, $template),
                $actor,
                null,
                null,
                null,
                'Suppression d\'une exigence qualité.',
                [
                    'workshop_routing_id' => $routing->getKey(),
                    'operation_code' => $lockedOperation->code,
                    'template_code' => $template?->code,
                    'scope' => $locked->scope->value,
                    'trigger' => $locked->trigger->value,
                ],
            );

            $locked->delete();
        }, attempts: 3);
    }

    /**
     * Erreurs qualité d'une gamme.
     *
     * Utilisé par WorkshopRoutingValidationService : une gamme ne peut être
     * activée ni affectée si une exigence référence un modèle inactif ou si
     * son échantillonnage est incompatible avec sa portée.
     *
     * @return array<string, array<int, string>>
     */
    public function errorsFor(WorkshopRouting $routing): array
    {
        $errors = [];

        $requirements = WorkshopRoutingOperationQualityRequirement::query()
            ->whereIn('workshop_routing_operation_id', $routing->operations()->select('id'))
            ->with(['template', 'operation'])
            ->get();

        foreach ($requirements as $requirement) {
            $template = $requirement->template;

            if ($template === null) {
                $errors['quality_requirements'][] = 'Une exigence qualité référence un modèle de contrôle introuvable.';

                continue;
            }

            $activeVersion = $template->activeVersion();

            if (! $template->is_active || $activeVersion === null) {
                $errors['quality_requirements'][] =
                    "Le modèle « {$template->code} » exigé par l'opération « {$requirement->operation?->code} » est inactif.";

                continue;
            }

            if ($requirement->scope !== QualityRequirementScope::Item) {
                foreach ($activeVersion->points as $point) {
                    if ($point->sampling_mode === QualityControlSamplingMode::EveryN) {
                        $errors['quality_requirements'][] =
                            "Le point « {$point->label} » du modèle « {$template->code} » utilise un échantillonnage par fréquence, incompatible avec la portée « {$requirement->scope->getLabel()} ».";
                    }
                }
            }
        }

        return $errors;
    }

    public function isValidForRouting(WorkshopRouting $routing): bool
    {
        return $this->errorsFor($routing) === [];
    }

    /**
     * @throws ValidationException
     */
    public function assertValidForRouting(WorkshopRouting $routing): void
    {
        $errors = $this->errorsFor($routing);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return Validator::make($data, [
            'quality_control_template_id' => [...$required, 'integer', Rule::exists('quality_control_templates', 'id')],
            'scope' => [...$required, Rule::enum(QualityRequirementScope::class)],
            'trigger' => [...$required, Rule::enum(QualityRequirementTrigger::class)],
            'is_mandatory' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:1'],
        ])->validate();
    }

    /**
     * @return array{0: WorkshopRouting, 1: WorkshopRoutingOperation}
     */
    protected function lockOperationContext(WorkshopRoutingOperation $operation): array
    {
        $routing = $operation->routing()->first();

        if ($routing === null) {
            $this->fail('routing', 'Cette gamme n\'existe plus.');
        }

        $lockedRouting = WorkshopRouting::query()->lockForUpdate()->find($routing->getKey());

        if ($lockedRouting === null) {
            $this->fail('routing', 'Cette gamme n\'existe plus.');
        }

        $lockedOperation = $lockedRouting->operations()
            ->whereKey($operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedOperation === null) {
            $this->fail('operation', 'Cette opération n\'appartient pas à cette gamme.');
        }

        return [$lockedRouting, $lockedOperation];
    }

    protected function lockTemplate(int $templateId): QualityControlTemplate
    {
        $template = QualityControlTemplate::query()->lockForUpdate()->find($templateId);

        if ($template === null) {
            $this->fail('quality_control_template_id', 'Le modèle de contrôle est introuvable.');
        }

        return $template;
    }

    protected function assertTemplateUsable(QualityControlTemplate $template): void
    {
        if (! $template->is_active || $template->activeVersion() === null) {
            $this->fail(
                'quality_control_template_id',
                "Le modèle « {$template->code} » est inactif et ne peut pas être exigé dans une gamme.",
            );
        }
    }

    protected function assertNotDuplicated(
        WorkshopRoutingOperation $operation,
        QualityControlTemplate $template,
        ?int $ignoreRequirementId = null,
    ): void {
        $query = $operation->qualityRequirements()
            ->where('quality_control_template_id', $template->getKey());

        if ($ignoreRequirementId !== null) {
            $query->whereKeyNot($ignoreRequirementId);
        }

        if ($query->exists()) {
            $this->fail(
                'quality_control_template_id',
                "Le modèle « {$template->code} » est déjà exigé par cette opération.",
            );
        }
    }

    protected function assertScopeSupportsSampling(
        QualityRequirementScope $scope,
        QualityControlTemplate $template,
    ): void {
        if ($scope === QualityRequirementScope::Item) {
            return;
        }

        $activeVersion = $template->activeVersion();

        if ($activeVersion === null) {
            return;
        }

        foreach ($activeVersion->points as $point) {
            if ($point->sampling_mode === QualityControlSamplingMode::EveryN) {
                $this->fail(
                    'scope',
                    "Le point « {$point->label} » impose un échantillonnage par fréquence : la portée doit être « Repère / pièce ».",
                );
            }
        }
    }

    protected function nextPosition(WorkshopRoutingOperation $operation): int
    {
        return ((int) $operation->qualityRequirements()->max('position')) + 10;
    }

    protected function requirementReference(
        WorkshopRouting $routing,
        WorkshopRoutingOperation $operation,
        QualityControlTemplate $template,
    ): string {
        return $routing->code.'/'.$operation->code.'/'.$template->code;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
