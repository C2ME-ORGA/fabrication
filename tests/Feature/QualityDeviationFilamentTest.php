<?php

namespace Tests\Feature;

use App\Enums\QualityControlStatus;
use App\Enums\QualityDeviationScope;
use App\Enums\QualityDeviationStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\QualityDeviations\Pages\CreateQualityDeviation;
use App\Filament\Resources\QualityDeviations\Pages\ViewQualityDeviation;
use App\Models\QualityDeviation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityDeviationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

class QualityDeviationFilamentTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    public function test_an_admin_can_list_and_view_quality_deviations(): void
    {
        $admin = User::factory()->admin()->create();
        $deviation = QualityDeviation::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/quality-deviations')
            ->assertOk()
            ->assertSee($deviation->reference);

        $this->actingAs($admin)
            ->get('/admin/quality-deviations/'.$deviation->getKey())
            ->assertOk()
            ->assertSee($deviation->reference)
            ->assertSee($deviation->reason);
    }

    public function test_a_quality_manager_can_request_a_deviation_from_filament(): void
    {
        $qualityManager = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control, $point] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => true,
        ]);
        $this->addValidatedResult($workOrder, $point, false);

        $this->actingAs($qualityManager);

        Livewire::test(CreateQualityDeviation::class)
            ->fillForm([
                'work_order_id' => $workOrder->getKey(),
                'scope' => QualityDeviationScope::Control->value,
                'work_order_quality_control_id' => $control->getKey(),
                'reason' => 'Demande de dérogation créée depuis Filament.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('quality_deviations', [
            'work_order_id' => $workOrder->getKey(),
            'scope' => 'control',
            'status' => QualityDeviationStatus::Pending->value,
        ]);
    }

    public function test_a_quality_manager_can_approve_a_deviation_from_the_view_page(): void
    {
        $admin = User::factory()->admin()->create();
        $qualityManager = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control, $point] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => true,
        ]);
        $this->addValidatedResult($workOrder, $point, false);

        $deviation = app(QualityDeviationService::class)->request($workOrder, [
            'scope' => QualityDeviationScope::Control->value,
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Dérogation approuvée depuis l\'interface Filament.',
        ], $admin);

        $this->actingAs($qualityManager);

        Livewire::test(ViewQualityDeviation::class, ['record' => $deviation->getRouteKey()])
            ->callAction('approveQualityDeviation', data: ['comment' => 'Approbation Filament.'])
            ->assertNotified('Dérogation approuvée');

        $this->assertSame(QualityDeviationStatus::Active, $deviation->fresh()->status);
    }

    public function test_the_work_order_page_displays_quality_blockers(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $this->createControl($workOrder);

        $this->actingAs($admin)
            ->get('/admin/work-orders/'.$workOrder->getKey())
            ->assertOk()
            ->assertSee('Éligibilité qualité')
            ->assertSee('Bloquée qualité')
            ->assertSee('Contrôle obligatoire manquant');
    }

    public function test_an_operator_cannot_access_the_deviation_resource(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get('/admin/quality-deviations')
            ->assertForbidden();
    }
}
