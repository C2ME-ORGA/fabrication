<?php

namespace Database\Seeders;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Services\QualityControlPointService;
use App\Services\QualityControlTemplateService;
use App\Services\QualityControlTemplateVersionService;
use App\Services\WorkshopRoutingOperationService;
use App\Services\WorkshopRoutingQualityRequirementService;
use App\Services\WorkshopRoutingService;
use Illuminate\Database\Seeder;

/**
 * Jeu de démonstration qualité : un modèle actif multi-types et une gamme
 * modèle portant une exigence qualité obligatoire.
 *
 * Le seeder est idempotent : il ne recrée pas les référentiels déjà présents.
 */
class QualityDemoSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()
            ->where('is_quality_manager', true)
            ->orderBy('id')
            ->first();

        $template = $this->template($actor);
        $routing = $this->routing();

        $operation = $routing->operations()->orderBy('position')->first();

        if ($operation !== null && ! $operation->qualityRequirements()->exists()) {
            app(WorkshopRoutingQualityRequirementService::class)->create($operation, [
                'quality_control_template_id' => $template->getKey(),
                'scope' => QualityRequirementScope::Operation->value,
                'trigger' => QualityRequirementTrigger::OnCompletion->value,
                'is_mandatory' => true,
            ], $actor);
        }
    }

    protected function template(?User $actor): QualityControlTemplate
    {
        $existing = QualityControlTemplate::query()->where('code', 'QUAL-DEMO-01')->first();

        if ($existing !== null) {
            return $existing;
        }

        $template = app(QualityControlTemplateService::class)->create([
            'code' => 'QUAL-DEMO-01',
            'name' => 'Contrôle qualité de démonstration',
            'description' => 'Modèle de démonstration couvrant les quatre types de résultat.',
            'control_type' => QualityControlType::Visual->value,
        ], $actor);

        $version = $template->draftVersion();
        $points = app(QualityControlPointService::class);

        $points->create($version, [
            'label' => 'Aspect visuel',
            'instructions' => 'Vérifier l’absence de rayure, choc ou défaut de peinture.',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => 'all',
            'is_mandatory' => true,
            'is_critical' => false,
            'allow_deviation' => false,
        ]);

        $points->create($version, [
            'label' => 'Longueur contrôlée',
            'instructions' => 'Mesurer la longueur utile de la pièce.',
            'result_type' => QualityControlResultType::Numeric->value,
            'unit' => 'mm',
            'tolerance_min' => 999,
            'tolerance_max' => 1001,
            'sampling_mode' => 'all',
            'is_mandatory' => true,
            'is_critical' => true,
            'allow_deviation' => false,
        ]);

        $points->create($version, [
            'label' => 'Observation atelier',
            'instructions' => 'Consigner une observation et qualifier explicitement la conformité.',
            'result_type' => QualityControlResultType::Text->value,
            'sampling_mode' => 'all',
            'is_mandatory' => false,
            'is_critical' => false,
            'allow_deviation' => false,
        ]);

        $points->create($version, [
            'label' => 'Finition retenue',
            'instructions' => 'Sélectionner la finition constatée.',
            'result_type' => QualityControlResultType::Choice->value,
            'options' => [
                ['value' => 'standard', 'label' => 'Standard', 'conformant' => true],
                ['value' => 'a_reprendre', 'label' => 'À reprendre', 'conformant' => false],
            ],
            'sampling_mode' => 'all',
            'is_mandatory' => false,
            'is_critical' => false,
            'allow_deviation' => true,
        ]);

        app(QualityControlTemplateVersionService::class)->activate(
            $version->fresh(),
            $actor,
            'Version de démonstration FC-7.',
        );

        return $template->fresh();
    }

    protected function routing(): WorkshopRouting
    {
        $existing = WorkshopRouting::query()->where('code', 'GAM-QUAL-DEMO')->first();

        if ($existing !== null) {
            return $existing;
        }

        $routing = app(WorkshopRoutingService::class)->create([
            'code' => 'GAM-QUAL-DEMO',
            'name' => 'Gamme de démonstration qualité',
            'description' => 'Gamme modèle utilisée pour présenter les exigences qualité.',
        ]);

        $operations = app(WorkshopRoutingOperationService::class);

        $operations->create($routing, [
            'code' => 'QA-DECOUPE',
            'name' => 'Découpe et contrôle',
            'instructions' => 'Découper puis contrôler la pièce selon le modèle qualité.',
            'position' => 10,
            'estimated_duration_minutes' => 30,
            'is_mandatory' => true,
        ]);

        $operations->create($routing, [
            'code' => 'QA-FINITION',
            'name' => 'Finition',
            'instructions' => 'Appliquer la finition retenue.',
            'position' => 20,
            'estimated_duration_minutes' => 45,
            'is_mandatory' => true,
        ]);

        return app(WorkshopRoutingService::class)->activate($routing->fresh())->refresh();
    }
}
