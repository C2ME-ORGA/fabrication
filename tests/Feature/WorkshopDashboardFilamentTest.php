<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Filament\Workshop\Pages\WorkshopDashboard;
use App\Filament\Workshop\Pages\WorkshopSupervision;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkshopDashboardFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_access_the_workshop_dashboard_and_operations(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get('/workshop')
            ->assertRedirect(WorkshopDashboard::getUrl(panel: 'workshop'));

        $this->actingAs($operator)
            ->get(WorkshopDashboard::getUrl(panel: 'workshop'))
            ->assertOk()
            ->assertSee('Tableau de bord atelier')
            ->assertSee('Opérations bloquées')
            ->assertDontSee('Supervision atelier');

        $this->actingAs($operator)
            ->get('/workshop/operations')
            ->assertOk();
    }

    public function test_a_supervisor_can_access_the_supervision_page(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'code' => 'BLOCKED-VISIBLE',
            'name' => 'Opération bloquée visible',
            'status' => WorkshopOperationStatus::Blocked,
            'blocked_reason' => 'Attente matière visible',
        ]);

        $this->actingAs($supervisor)
            ->get(WorkshopDashboard::getUrl(panel: 'workshop'))
            ->assertOk()
            ->assertSee('Supervision atelier');

        $this->actingAs($supervisor)
            ->get(WorkshopSupervision::getUrl(panel: 'workshop'))
            ->assertOk()
            ->assertSee('Supervision atelier')
            ->assertSee('Chronomètres actifs')
            ->assertSee('BLOCKED-VISIBLE')
            ->assertSee('Attente matière visible')
            ->assertSee('Temps consommés par OF');
    }

    public function test_an_operator_cannot_access_the_supervision_page(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get(WorkshopSupervision::getUrl(panel: 'workshop'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_workshop_login(): void
    {
        $this->get(WorkshopDashboard::getUrl(panel: 'workshop'))
            ->assertRedirect('/workshop/login');
    }
}
