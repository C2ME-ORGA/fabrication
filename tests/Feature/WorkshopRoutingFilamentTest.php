<?php

namespace Tests\Feature;

use App\Filament\Resources\WorkshopRoutings\Pages\CreateWorkshopRouting;
use App\Filament\Resources\WorkshopRoutings\Pages\ListWorkshopRoutings;
use App\Filament\Resources\WorkshopRoutings\Pages\ViewWorkshopRouting;
use App\Filament\Resources\WorkshopRoutings\RelationManagers\DependenciesRelationManager;
use App\Filament\Resources\WorkshopRoutings\RelationManagers\OperationsRelationManager;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Services\WorkshopRoutingOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkshopRoutingFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_access_the_routing_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->create(['code' => 'GAM-UI-001', 'name' => 'Gamme UI']);

        $this->actingAs($admin)
            ->get('/admin/workshop-routings')
            ->assertOk()
            ->assertSee('GAM-UI-001');

        $this->actingAs($admin)
            ->get("/admin/workshop-routings/{$routing->id}")
            ->assertOk()
            ->assertSee('Gamme UI')
            ->assertSee('Opérations')
            ->assertSee('Dépendances');

        $this->actingAs($admin)
            ->get("/admin/workshop-routings/{$routing->id}/edit")
            ->assertOk();
    }

    public function test_a_workshop_supervisor_can_manage_routings_in_the_workshop_panel_but_not_the_admin_panel(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $routing = WorkshopRouting::factory()->create(['code' => 'GAM-SUP']);

        $this->actingAs($supervisor)
            ->get('/workshop/workshop-routings')
            ->assertOk()
            ->assertSee('GAM-SUP');

        $this->actingAs($supervisor)
            ->get("/workshop/workshop-routings/{$routing->id}/edit")
            ->assertOk();

        $this->actingAs($supervisor)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->get('/admin/workshop-routings')
            ->assertForbidden();
    }

    public function test_a_standard_operator_cannot_access_routing_management(): void
    {
        $operator = User::factory()->create();
        $routing = WorkshopRouting::factory()->create();

        $this->actingAs($operator)
            ->get('/admin/workshop-routings')
            ->assertForbidden();

        $this->actingAs($operator)
            ->get('/workshop/workshop-routings')
            ->assertForbidden();

        $this->actingAs($operator)
            ->get("/workshop/workshop-routings/{$routing->id}")
            ->assertForbidden();

        $this->actingAs($operator)
            ->get("/workshop/workshop-routings/{$routing->id}/edit")
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_workshop_login(): void
    {
        $this->get('/workshop/workshop-routings')
            ->assertRedirect('/workshop/login');
    }

    public function test_the_workshop_panel_does_not_expose_administration_resources(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();

        $this->actingAs($supervisor)
            ->get('/workshop/jobs')
            ->assertNotFound();

        $this->actingAs($supervisor)
            ->get('/workshop/work-orders')
            ->assertNotFound();

        $this->actingAs($supervisor)
            ->get('/workshop/documents')
            ->assertNotFound();

        $this->actingAs($supervisor)
            ->get('/admin/jobs')
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->get('/admin/work-orders')
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->get('/admin/documents')
            ->assertForbidden();
    }

    public function test_an_administrator_can_access_both_panels_and_routing_management(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/workshop/workshop-routings')
            ->assertOk();

        $this->actingAs($admin)
            ->get("/workshop/workshop-routings/{$routing->id}/edit")
            ->assertOk();
    }

    public function test_an_admin_can_create_a_routing_from_the_filament_form(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreateWorkshopRouting::class)
            ->fillForm([
                'code' => 'gam-100',
                'name' => 'Gamme créée depuis Filament',
                'description' => 'Description de la gamme',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $routing = WorkshopRouting::query()->where('code', 'GAM-100')->firstOrFail();

        $this->assertSame('Gamme créée depuis Filament', $routing->name);
        $this->assertFalse($routing->isActive());
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $admin = User::factory()->admin()->create();
        $active = WorkshopRouting::factory()->create(['code' => 'GAM-ACTIVE', 'name' => 'Active']);
        $inactive = WorkshopRouting::factory()->inactive()->create(['code' => 'GAM-INACTIVE', 'name' => 'Inactive']);

        $this->actingAs($admin);

        Livewire::test(ListWorkshopRoutings::class)
            ->searchTable('GAM-ACTIVE')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive])
            ->searchTable(null)
            ->filterTable('is_active', '0')
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_operations_can_be_created_edited_reordered_and_deleted_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->create();

        $this->actingAs($admin);

        $manager = $this->operationsManager($routing);

        $manager->callTableAction('createOperation', data: [
            'code' => 'debit',
            'name' => 'Débit matière',
            'estimated_duration_minutes' => 30,
            'is_mandatory' => true,
        ])->assertNotified('Opération ajoutée');

        $manager->callTableAction('createOperation', data: [
            'code' => 'percage',
            'name' => 'Perçage',
            'estimated_duration_minutes' => 15,
            'is_mandatory' => true,
        ])->assertNotified('Opération ajoutée');

        $operations = $routing->operations()->get();
        $this->assertSame(['DEBIT', 'PERCAGE'], $operations->pluck('code')->all());

        $manager->callTableAction('moveDown', $operations->first());

        $this->assertSame(['PERCAGE', 'DEBIT'], $routing->operations()->pluck('code')->all());

        $manager->callTableAction('edit', $operations->first(), data: [
            'name' => 'Débit matière modifié',
        ])
            ->assertNotified('Opération modifiée')
            ->callTableAction('manageDependencies', $operations->last(), data: [
                'dependencies' => [$operations->first()->getKey()],
            ])
            ->assertNotified('Dépendances enregistrées');

        $this->assertSame('Débit matière modifié', $operations->first()->fresh()->name);
        $this->assertTrue(
            $operations->last()->fresh()->dependencies()->whereKey($operations->first()->getKey())->exists(),
        );

        $manager->callTableAction('delete', $operations->last())
            ->assertNotified('Action refusée');

        $this->assertDatabaseHas('workshop_routing_operations', ['id' => $operations->last()->getKey()]);
    }

    public function test_dependencies_can_be_added_and_deleted_from_the_dependencies_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->create();
        $operationService = app(WorkshopRoutingOperationService::class);

        $cutting = $operationService->create($routing, ['code' => 'DEBIT', 'name' => 'Débit']);
        $drilling = $operationService->create($routing, ['code' => 'PERCAGE', 'name' => 'Perçage']);

        $this->actingAs($admin);

        $manager = Livewire::test(DependenciesRelationManager::class, [
            'ownerRecord' => $routing,
            'pageClass' => ViewWorkshopRouting::class,
        ]);

        $manager->callTableAction('addDependency', data: [
            'workshop_routing_operation_id' => $drilling->getKey(),
            'depends_on_operation_id' => $cutting->getKey(),
        ])->assertNotified('Dépendance ajoutée');

        $dependency = $routing->operationDependencies()->firstOrFail();

        $manager->callTableAction('delete', $dependency)
            ->assertNotified('Dépendance supprimée');

        $this->assertDatabaseMissing('workshop_routing_operation_dependencies', [
            'id' => $dependency->getKey(),
        ]);
    }

    public function test_activation_is_refused_from_the_action_when_the_routing_is_empty(): void
    {
        $admin = User::factory()->admin()->create();
        $routing = WorkshopRouting::factory()->inactive()->create();

        $this->actingAs($admin);

        Livewire::test(ListWorkshopRoutings::class)
            ->callTableAction('activateRouting', $routing)
            ->assertNotified('Activation refusée');

        $this->assertFalse($routing->fresh()->isActive());
    }

    protected function operationsManager(WorkshopRouting $routing): Testable
    {
        return Livewire::test(OperationsRelationManager::class, [
            'ownerRecord' => $routing,
            'pageClass' => ViewWorkshopRouting::class,
        ]);
    }
}
