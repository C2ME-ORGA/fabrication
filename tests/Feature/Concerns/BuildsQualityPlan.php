<?php

namespace Tests\Feature\Concerns;

use App\Enums\QualityControlResultType;
use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Enums\WorkOrderStatus;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use App\Services\QualityControlPointService;
use App\Services\QualityControlTemplateService;
use App\Services\QualityControlTemplateVersionService;
use App\Services\WorkOrderRoutingService;
use App\Services\WorkshopRoutingQualityRequirementService;

trait BuildsQualityPlan
{
    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    protected function makeActiveTemplate(User $actor, array $points, ?string $code = null): QualityControlTemplate
    {
        $templates = app(QualityControlTemplateService::class);
        $pointsService = app(QualityControlPointService::class);
        $versions = app(QualityControlTemplateVersionService::class);

        $template = $templates->create([
            'code' => $code ?? 'TPL-'.uniqid(),
            'name' => 'Modèle de contrôle '.uniqid(),
            'control_type' => 'dimensional',
        ], $actor);

        $version = $template->draftVersion();

        foreach ($points as $index => $point) {
            $pointsService->create($version, array_merge([
                'label' => 'Point '.($index + 1),
                'result_type' => QualityControlResultType::Conformity->value,
                'sampling_mode' => 'all',
                'is_mandatory' => true,
            ], $point));
        }

        $versions->activate($version->fresh(), $actor, 'Version de test.');

        return $template->fresh();
    }

    /**
     * @return array{0: WorkshopRouting, 1: WorkshopRoutingOperation, 2: WorkshopRoutingOperationQualityRequirement}
     */
    protected function assignRequirement(
        User $actor,
        WorkOrder $workOrder,
        QualityControlTemplate $template,
        string $scope = QualityRequirementScope::Operation->value,
        string $trigger = QualityRequirementTrigger::OnCompletion->value,
        bool $mandatory = true,
    ): array {
        $routing = WorkshopRouting::factory()->create();
        $operation = WorkshopRoutingOperation::factory()->forRouting($routing)->create();

        $requirement = app(WorkshopRoutingQualityRequirementService::class)->create($operation, [
            'quality_control_template_id' => $template->getKey(),
            'scope' => $scope,
            'trigger' => $trigger,
            'is_mandatory' => $mandatory,
        ], $actor);

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing->fresh(), $actor);

        return [$routing->fresh(), $operation->fresh(), $requirement];
    }

    /**
     * @return array<int, WorkOrderItem>
     */
    protected function createItems(WorkOrder $workOrder, int $count): array
    {
        $items = [];

        for ($index = 1; $index <= $count; $index++) {
            $items[] = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
                'mark' => sprintf('REP-%03d', $index),
            ]);
        }

        return $items;
    }

    protected function release(WorkOrder $workOrder): WorkOrder
    {
        $workOrder->forceFill([
            'status' => WorkOrderStatus::Released,
            'released_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ])->save();

        return $workOrder->refresh();
    }
}
