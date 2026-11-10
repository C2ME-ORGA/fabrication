<?php

namespace Tests\Feature\Concerns;

use App\Enums\QualityControlStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Job;
use App\Models\NonConformity;
use App\Models\QualityControlResult;
use App\Models\QualityControlResultValidation;
use App\Models\QualityDeviation;
use App\Models\QualityDeviationTarget;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\WorkOrderService;

trait BuildsQualityEligibilityScenarios
{
    /**
     * @param  array<string, mixed>  $controlAttributes
     * @param  array<string, mixed>  $pointAttributes
     * @return array{0: WorkOrderQualityControl, 1: WorkOrderQualityControlPoint}
     */
    protected function createControl(
        WorkOrder $workOrder,
        array $controlAttributes = [],
        array $pointAttributes = [],
    ): array {
        $control = WorkOrderQualityControl::factory()->create(array_merge([
            'work_order_id' => $workOrder->getKey(),
            'control_type' => 'visual',
            'scope' => 'operation',
            'trigger' => 'on_completion',
            'is_mandatory' => true,
            'status' => QualityControlStatus::Pending,
        ], $controlAttributes));

        $point = WorkOrderQualityControlPoint::factory()->create(array_merge([
            'work_order_quality_control_id' => $control->getKey(),
            'result_type' => 'conformity',
            'is_mandatory' => true,
            'is_critical' => false,
            'allow_deviation' => false,
            'sampling_mode' => 'all',
            'required_results' => 1,
        ], $pointAttributes));

        return [$control->refresh(), $point->refresh()];
    }

    protected function addValidatedResult(
        WorkOrder $workOrder,
        WorkOrderQualityControlPoint $point,
        bool $conforming = true,
        int $validations = 1,
    ): QualityControlResult {
        $result = QualityControlResult::factory()->create([
            'work_order_quality_control_point_id' => $point->getKey(),
            'work_order_id' => $workOrder->getKey(),
            'work_order_item_id' => null,
            'value_boolean' => $conforming,
            'is_conform' => $conforming,
            'performed_by' => User::factory()->create()->getKey(),
            'recorded_by' => User::factory()->create()->getKey(),
            'performed_at' => now(),
            'created_at' => now(),
        ]);

        for ($index = 0; $index < $validations; $index++) {
            QualityControlResultValidation::factory()->create([
                'quality_control_result_id' => $result->getKey(),
                'decision' => 'validated',
                'validated_by' => User::factory()->create()->getKey(),
                'validated_at' => now(),
            ]);
        }

        return $result->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function addNonConformity(WorkOrder $workOrder, array $attributes = []): NonConformity
    {
        return NonConformity::factory()->create(array_merge([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $targetAttributes
     * @param  array<string, mixed>  $attributes
     */
    protected function addActiveDeviation(
        WorkOrder $workOrder,
        array $targetAttributes,
        array $attributes = [],
    ): QualityDeviation {
        $deviation = QualityDeviation::factory()->active()->create(array_merge([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
        ], $attributes));

        QualityDeviationTarget::factory()->create(array_merge([
            'quality_deviation_id' => $deviation->getKey(),
            'work_order_quality_control_id' => null,
            'work_order_quality_control_point_id' => null,
            'non_conformity_id' => null,
        ], $targetAttributes));

        return $deviation->refresh()->load('targets');
    }

    protected function readyWorkOrderWithBeApproval(User $admin): WorkOrder
    {
        $job = Job::factory()->active()->create();
        $service = app(WorkOrderService::class);

        $workOrder = $service->create([
            'job_id' => $job->getKey(),
            'title' => 'OF qualité Lot 5',
        ], $admin);

        $workOrder = $service->transitionTo($workOrder, WorkOrderStatus::Ready, $admin);

        return $service->approveByBE($workOrder, $admin);
    }
}
