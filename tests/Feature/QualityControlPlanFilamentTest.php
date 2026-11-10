<?php

namespace Tests\Feature;

use App\Enums\QualityRequirementScope;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\QualityControlsRelationManager;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityControlResultService;
use App\Services\WorkOrderQualityPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class QualityControlPlanFilamentTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    public function test_the_work_order_page_shows_the_generated_quality_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle visuel']]);
        $workOrder = WorkOrder::factory()->create();

        $this->assignRequirement($admin, $workOrder, $template);

        $control = $workOrder->fresh()->qualityControls()->sole();

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertSee('Contrôles qualité');

        $this->manager($workOrder->fresh())
            ->assertCanSeeTableRecords([$control]);
    }

    public function test_an_admin_can_record_a_result_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle visuel']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $point = $control->points()->sole();

        $this->actingAs($admin);

        $this->manager($workOrder->fresh())
            ->callTableAction('recordResult', $control, data: [
                'point_id' => $point->getKey(),
                'value_boolean' => '1',
                'comment' => 'Contrôle conforme.',
            ])
            ->assertNotified('Résultat enregistré');

        $this->assertDatabaseHas('quality_control_results', [
            'work_order_quality_control_point_id' => $point->getKey(),
            'performed_by' => $admin->getKey(),
            'value_boolean' => true,
            'is_conform' => true,
        ]);
    }

    public function test_an_admin_can_validate_a_result_recorded_by_someone_else(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle visuel']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $point = $control->points()->sole();
        $result = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => true],
            $operator,
        );

        $this->actingAs($admin);

        $this->manager($workOrder->fresh())
            ->callTableAction('validateResult', $control, data: [
                'result_id' => $result->getKey(),
                'decision' => 'validated',
            ])
            ->assertNotified('Décision enregistrée');

        $this->assertDatabaseHas('quality_control_result_validations', [
            'quality_control_result_id' => $result->getKey(),
            'validated_by' => $admin->getKey(),
            'decision' => 'validated',
        ]);
    }

    public function test_an_admin_can_cancel_a_pending_control_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle visuel']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();

        $this->actingAs($admin);

        $this->manager($workOrder->fresh())
            ->callTableAction('cancelControl', $control, data: [
                'reason' => 'Contrôle remplacé par une autre instruction.',
            ])
            ->assertNotified('Contrôle annulé');

        $this->assertSame('cancelled', $control->fresh()->status->value);
    }

    public function test_a_manual_control_can_be_deleted_from_the_relation_manager_before_release_only(): void
    {
        $admin = User::factory()->admin()->create();
        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle manuel']]);
        $workOrder = WorkOrder::factory()->create();

        $control = app(WorkOrderQualityPlanService::class)->addTemplate(
            $workOrder,
            $template,
            ['scope' => QualityRequirementScope::Operation->value],
            $admin,
        );

        $this->actingAs($admin);

        $this->manager($workOrder->fresh())
            ->callTableAction('deleteManualControl', $control)
            ->assertNotified('Contrôle supprimé');

        $this->assertDatabaseMissing('work_order_quality_controls', ['id' => $control->getKey()]);

        $releasedWorkOrder = WorkOrder::factory()->create();
        $releasedControl = app(WorkOrderQualityPlanService::class)->addTemplate(
            $releasedWorkOrder,
            $template,
            ['scope' => QualityRequirementScope::Operation->value],
            $admin,
        );

        $this->release($releasedWorkOrder);

        $this->manager($releasedWorkOrder->fresh())
            ->assertTableActionHidden('deleteManualControl', $releasedControl);
    }

    protected function manager(WorkOrder $workOrder): mixed
    {
        return Livewire::test(QualityControlsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ]);
    }
}
