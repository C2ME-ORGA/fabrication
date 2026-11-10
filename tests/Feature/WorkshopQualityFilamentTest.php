<?php

namespace Tests\Feature;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityControlResultType;
use App\Enums\QualityControlStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\NonConformitiesRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\QualityDeviationsRelationManager;
use App\Filament\Workshop\Pages\WorkshopDashboard;
use App\Filament\Workshop\Pages\WorkshopSupervision;
use App\Filament\Workshop\Resources\QualityControls\Pages\ListQualityControls;
use App\Filament\Workshop\Resources\QualityControls\Pages\ViewQualityControl;
use App\Models\NonConformity;
use App\Models\QualityDeviation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Services\QualityControlResultService;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

class WorkshopQualityFilamentTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    protected ?Panel $previousPanel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('workshop'));
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel($this->previousPanel);

        parent::tearDown();
    }

    public function test_an_operator_can_consult_and_filter_workshop_quality_controls(): void
    {
        $operator = User::factory()->create();
        $workOrder = $this->releasedWorkOrder();

        [$pending] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Pending,
            'template_name' => 'Contrôle à réaliser',
        ], ['label' => 'Contrôle en attente']);

        [$passed] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Passed,
            'template_name' => 'Contrôle terminé',
        ], ['label' => 'Contrôle conforme']);

        $this->actingAs($operator)
            ->get('/workshop/quality-controls')
            ->assertOk()
            ->assertSee('Contrôle à réaliser');

        Livewire::test(ListQualityControls::class)
            ->assertCanSeeTableRecords([$pending, $passed])
            ->filterTable('to_do', true)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$passed])
            ->resetTableFilters()
            ->filterTable('work_order_id', $workOrder->getKey())
            ->assertCanSeeTableRecords([$pending, $passed]);
    }

    public function test_numeric_tolerances_and_textual_conformity_are_recorded_from_the_workshop_screen(): void
    {
        $operator = User::factory()->create();
        $workOrder = $this->releasedWorkOrder();

        [$numericControl, $numericPoint] = $this->createControl($workOrder, [], [
            'label' => 'Longueur',
            'result_type' => QualityControlResultType::Numeric,
            'unit' => 'mm',
            'tolerance_min' => 10,
            'tolerance_max' => 20,
        ]);

        $this->actingAs($operator);

        Livewire::test(ViewQualityControl::class, ['record' => $numericControl->getKey()])
            ->callAction('recordQualityResult', data: [
                'point_id' => $numericPoint->getKey(),
                'value_numeric' => '20.1',
            ])
            ->assertNotified('Résultat enregistré');

        $this->assertDatabaseHas('quality_control_results', [
            'work_order_quality_control_point_id' => $numericPoint->getKey(),
            'is_conform' => false,
            'tolerance_min_snapshot' => 10,
            'tolerance_max_snapshot' => 20,
        ]);

        [$choiceControl, $choicePoint] = $this->createControl($workOrder, [], [
            'label' => 'Aspect',
            'result_type' => QualityControlResultType::Choice,
            'options' => [
                ['value' => 'ok', 'label' => 'Conforme', 'conformant' => true],
                ['value' => 'nok', 'label' => 'Non conforme', 'conformant' => false],
            ],
        ]);

        Livewire::test(ViewQualityControl::class, ['record' => $choiceControl->getKey()])
            ->callAction('recordQualityResult', data: [
                'point_id' => $choicePoint->getKey(),
                'value_choice' => 'nok',
            ])
            ->assertNotified('Résultat enregistré');

        $this->assertDatabaseHas('quality_control_results', [
            'work_order_quality_control_point_id' => $choicePoint->getKey(),
            'value_choice' => 'nok',
            'is_conform' => false,
        ]);

        [$textControl, $textPoint] = $this->createControl($workOrder, [], [
            'label' => 'Observation',
            'result_type' => QualityControlResultType::Text,
        ]);

        Storage::fake('local');

        Livewire::test(ViewQualityControl::class, ['record' => $textControl->getKey()])
            ->callAction('recordQualityResult', data: [
                'point_id' => $textPoint->getKey(),
                'value_text' => 'Rayure légère sur la face visible',
                'is_conform' => '0',
                'evidence' => [UploadedFile::fake()->image('preuve.jpg')],
            ])
            ->assertNotified('Résultat enregistré');

        $this->assertDatabaseHas('quality_control_results', [
            'work_order_quality_control_point_id' => $textPoint->getKey(),
            'value_text' => 'Rayure légère sur la face visible',
            'is_conform' => false,
        ]);

        $this->assertDatabaseHas('quality_control_result_attachments', [
            'quality_control_result_id' => $textPoint->results()->sole()->getKey(),
        ]);
    }

    public function test_validation_and_critical_second_validation_follow_the_policies(): void
    {
        $operator = User::factory()->create();
        $firstValidator = User::factory()->workshopSupervisor()->create();
        $secondValidator = User::factory()->qualityManager()->create();
        $workOrder = $this->releasedWorkOrder();

        [$control, $point] = $this->createControl($workOrder, [], [
            'label' => 'Point critique',
            'is_critical' => true,
        ]);

        $result = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => true],
            $operator,
        );

        $this->actingAs($operator);
        Livewire::test(ViewQualityControl::class, ['record' => $control->getKey()])
            ->assertActionHidden('validateQualityResult')
            ->assertActionHidden('secondQualityValidation');

        $this->actingAs($firstValidator);
        Livewire::test(ViewQualityControl::class, ['record' => $control->getKey()])
            ->assertActionVisible('validateQualityResult')
            ->callAction('validateQualityResult', data: [
                'result_id' => $result->getKey(),
                'decision' => 'validated',
            ])
            ->assertNotified('Décision enregistrée')
            ->assertActionHidden('secondQualityValidation');

        $this->actingAs($secondValidator);
        Livewire::test(ViewQualityControl::class, ['record' => $control->getKey()])
            ->assertActionVisible('secondQualityValidation')
            ->callAction('secondQualityValidation', data: [
                'result_id' => $result->getKey(),
                'comment' => 'Seconde validation du point critique.',
            ])
            ->assertNotified('Seconde validation enregistrée');

        $this->assertSame(2, $result->fresh()->validatedUsersCount());
        $this->assertSame(QualityControlStatus::Passed, $control->fresh()->status);
    }

    public function test_a_non_conformity_can_be_declared_from_a_failed_control_without_duplicating_the_nc_workflow(): void
    {
        $operator = User::factory()->create();
        $workOrder = $this->releasedWorkOrder();

        [$failedControl] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], ['label' => 'Contrôle non conforme']);

        [$pendingControl] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Pending,
        ], ['label' => 'Contrôle en attente']);

        $this->actingAs($operator);

        Livewire::test(ViewQualityControl::class, ['record' => $pendingControl->getKey()])
            ->assertActionHidden('workshopDeclareNonConformity');

        Livewire::test(ViewQualityControl::class, ['record' => $failedControl->getKey()])
            ->assertActionVisible('workshopDeclareNonConformity')
            ->callAction('workshopDeclareNonConformity', data: [
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Process->value,
                'detected_at' => now()->subMinute(),
                'description' => 'Défaut constaté sur le contrôle qualité en atelier.',
                'criterion' => 'Conformité visuelle attendue.',
            ])
            ->assertNotified('Non-conformité déclarée');

        $this->assertDatabaseHas('non_conformities', [
            'work_order_id' => $workOrder->getKey(),
            'work_order_quality_control_id' => $failedControl->getKey(),
            'created_by' => $operator->getKey(),
            'status' => NonConformityStatus::Open->value,
        ]);
    }

    public function test_the_quality_eligibility_diagnostic_is_displayed_on_the_control_screen(): void
    {
        $operator = User::factory()->create();
        $workOrder = $this->releasedWorkOrder();

        [$control] = $this->createControl($workOrder);

        $this->actingAs($operator)
            ->get('/workshop/quality-controls/'.$control->getKey())
            ->assertOk()
            ->assertSee('Éligibilité qualité de l’OF')
            ->assertSee('Bloquée qualité')
            ->assertSee('Blocages');
    }

    public function test_the_dashboard_and_supervision_pages_expose_quality_metrics_and_alerts(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $workOrder = $this->releasedWorkOrder();

        [$lateControl] = $this->createControl($workOrder, [
            'required_by_at' => now()->subDay(),
        ]);

        NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        QualityDeviation::factory()->active()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'expires_at' => now()->addDays(3),
        ]);

        $this->actingAs($operator);

        $dashboard = Livewire::test(WorkshopDashboard::class)
            ->assertSee('Taux de conformité')
            ->assertSee('Contrôles en retard')
            ->assertSee('NC ouvertes')
            ->assertSee('Dérogations actives');

        $this->assertSame(1, $dashboard->get('quality')['controls_late']);
        $this->assertSame(1, $dashboard->get('quality')['non_conformities_open']);
        $this->assertSame(1, $dashboard->get('quality')['deviations_active']);

        $this->actingAs($supervisor);

        $supervision = Livewire::test(WorkshopSupervision::class)
            ->assertSee('Contrôles qualité à réaliser')
            ->assertSee('Contrôles en retard')
            ->assertSee("NC en dépassement d'échéance", false)
            ->assertSee('Dérogations expirant sous 7 jours')
            ->assertSee($lateControl->template_name);

        $this->assertSame(1, $supervision->get('quality')['pending_controls_count']);
        $this->assertSame(1, $supervision->get('quality')['overdue_controls_count']);
        $this->assertSame(1, $supervision->get('quality')['overdue_non_conformities_count']);
        $this->assertSame(1, $supervision->get('quality')['expiring_deviations_count']);
    }

    public function test_the_work_order_list_exposes_quality_badges_and_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = $this->releasedWorkOrder();

        $this->createControl($workOrder, ['status' => QualityControlStatus::Pending]);

        NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
        ]);

        $otherWorkOrder = $this->releasedWorkOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListWorkOrders::class)
            ->assertSee('Contrôles à faire')
            ->assertSee('NC ouvertes')
            ->assertCanSeeTableRecords([$workOrder, $otherWorkOrder])
            ->filterTable('quality_controls_pending', true)
            ->assertCanSeeTableRecords([$workOrder])
            ->assertCanNotSeeTableRecords([$otherWorkOrder])
            ->resetTableFilters()
            ->filterTable('open_non_conformities', true)
            ->assertCanSeeTableRecords([$workOrder])
            ->assertCanNotSeeTableRecords([$otherWorkOrder]);
    }

    public function test_the_work_order_page_lists_associated_non_conformities_and_deviations(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = $this->releasedWorkOrder();

        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
        ]);

        $deviation = QualityDeviation::factory()->active()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
        ]);

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->getKey()}")
            ->assertOk()
            ->assertSee('Non-conformités')
            ->assertSee('Dérogations qualité');

        Livewire::test(NonConformitiesRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ])->assertCanSeeTableRecords([$nonConformity]);

        Livewire::test(QualityDeviationsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ])->assertCanSeeTableRecords([$deviation]);
    }

    public function test_the_paginated_control_list_handles_a_large_demo_set(): void
    {
        $operator = User::factory()->create();
        $workOrder = $this->releasedWorkOrder();

        $controls = collect(range(1, 60))->map(
            fn (int $index): WorkOrderQualityControl => $this->createControl(
                $workOrder,
                [
                    'template_name' => sprintf('Contrôle de charge %02d', $index),
                    'required_by_at' => now()->addMinutes($index),
                ],
                ['label' => sprintf('Point de charge %02d', $index)],
            )[0],
        );

        $this->actingAs($operator);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = Livewire::test(ListQualityControls::class)
            ->assertCountTableRecords(60)
            ->assertCanSeeTableRecords($controls->take(25))
            ->assertCanNotSeeTableRecords([$controls->last()]);

        $this->assertLessThanOrEqual(80, count(DB::getQueryLog()));

        DB::disableQueryLog();

        $page->call('setPage', 2)
            ->assertCanSeeTableRecords($controls->slice(25, 25));
    }

    public function test_an_operator_cannot_reach_supervision_quality_alerts(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get(WorkshopSupervision::getUrl(panel: 'workshop'))
            ->assertForbidden();
    }

    protected function releasedWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()->status(WorkOrderStatus::Released)->create([
            'released_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);
    }
}
