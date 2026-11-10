<?php

namespace Tests\Feature;

use App\Enums\QualityRequirementTrigger;
use App\Enums\WorkshopOperationStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Services\WorkOrderOperationService;
use App\Services\WorkOrderRoutingService;
use App\Services\WorkshopRoutingQualityRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class QualityControlTriggerTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    public function test_operation_completion_sets_required_by_at_only_for_on_completion_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();

        $onCompletion = $this->makeActiveTemplate($admin, [['label' => 'Contrôle à la terminaison']], 'TPL-COMPLETE');
        $final = $this->makeActiveTemplate($admin, [['label' => 'Contrôle final']], 'TPL-FINAL');
        $onStart = $this->makeActiveTemplate($admin, [['label' => 'Contrôle au démarrage']], 'TPL-START');

        $workOrder = WorkOrder::factory()->create();
        $routing = WorkshopRouting::factory()->create();
        $operation = WorkshopRoutingOperation::factory()->forRouting($routing)->create();

        $requirements = app(WorkshopRoutingQualityRequirementService::class);

        foreach ([
            [$onCompletion, QualityRequirementTrigger::OnCompletion],
            [$final, QualityRequirementTrigger::Final],
            [$onStart, QualityRequirementTrigger::OnStart],
        ] as [$template, $trigger]) {
            $requirements->create($operation, [
                'quality_control_template_id' => $template->getKey(),
                'scope' => 'operation',
                'trigger' => $trigger->value,
            ], $admin);
        }

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing->fresh(), $admin);

        $controls = $workOrder->fresh()->qualityControls()->get()->keyBy('template_code');
        $this->assertNull($controls['TPL-COMPLETE']->required_by_at);
        $this->assertNull($controls['TPL-FINAL']->required_by_at);
        $this->assertNull($controls['TPL-START']->required_by_at);

        $this->release($workOrder);

        $operation = $workOrder->fresh()->operations()->sole();
        $operation->forceFill([
            'status' => WorkshopOperationStatus::InProgress,
            'started_at' => now()->subMinutes(30),
        ])->save();

        app(WorkOrderOperationService::class)->complete($operation->fresh(), $operator);

        $this->assertNotNull($controls['TPL-COMPLETE']->fresh()->required_by_at);
        $this->assertNull($controls['TPL-FINAL']->fresh()->required_by_at);
        $this->assertNull($controls['TPL-START']->fresh()->required_by_at);
        $this->assertSame('pending', $controls['TPL-COMPLETE']->fresh()->status->value);

        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $controls['TPL-COMPLETE']->getKey(),
            'event' => 'control_required',
        ]);

        $this->assertDatabaseMissing('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $controls['TPL-FINAL']->getKey(),
            'event' => 'control_required',
        ]);

        $this->assertDatabaseMissing('quality_audit_logs', [
            'subject_type' => 'control',
            'subject_id' => $controls['TPL-START']->getKey(),
            'event' => 'control_required',
        ]);
    }
}
