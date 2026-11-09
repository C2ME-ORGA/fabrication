<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\WorkOrderOperationsRelationManager;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkshopRouting;
use App\Services\WorkOrderRoutingService;
use App\Services\WorkshopRoutingOperationService;
use App\Services\WorkshopRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderRoutingFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_work_order_page_shows_the_assigned_routing_and_operations(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $routing = $this->activeRouting('GAM-UI-ASSIGN', ['DEBIT' => [], 'PERCAGE' => ['DEBIT']]);

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing, $admin);

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertSee('Gamme atelier')
            ->assertSee('GAM-UI-ASSIGN')
            ->assertSee('Opérations de fabrication');

        $this->operationsManager($workOrder->fresh())
            ->assertCanSeeTableRecords($workOrder->operations()->get())
            ->assertSee('DEBIT')
            ->assertSee('PERCAGE');
    }

    public function test_an_admin_can_assign_a_routing_from_the_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $routing = $this->activeRouting('GAM-UI-ACTION', ['DEBIT' => []]);

        $this->actingAs($admin);

        $this->operationsManager($workOrder)
            ->callTableAction('assignWorkshopRouting', data: [
                'workshop_routing_id' => $routing->getKey(),
            ])
            ->assertNotified('Gamme affectée');

        $workOrder->refresh();

        $this->assertSame($routing->getKey(), $workOrder->workshop_routing_id);
        $this->assertSame(1, $workOrder->operations()->count());
    }

    public function test_an_admin_can_replace_a_routing_before_release(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $first = $this->activeRouting('GAM-UI-FIRST', ['DEBIT' => []]);
        $second = $this->activeRouting('GAM-UI-SECOND', ['PERCAGE' => [], 'ASSEMBLAGE' => ['PERCAGE']]);

        app(WorkOrderRoutingService::class)->assign($workOrder, $first, $admin);

        $this->actingAs($admin);

        $this->operationsManager($workOrder->fresh())
            ->callTableAction('replaceWorkshopRouting', data: [
                'workshop_routing_id' => $second->getKey(),
            ])
            ->assertNotified('Gamme remplacée');

        $this->assertSame($second->getKey(), $workOrder->fresh()->workshop_routing_id);
        $this->assertSame(['PERCAGE', 'ASSEMBLAGE'], $workOrder->operations()->pluck('code')->all());
    }

    public function test_the_relation_manager_is_read_only_after_release(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $routing = $this->activeRouting('GAM-UI-LOCKED', ['DEBIT' => [], 'PERCAGE' => ['DEBIT']]);

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing, $admin);
        $workOrder->forceFill(['status' => WorkOrderStatus::Released])->save();

        $operation = $workOrder->operations()->firstOrFail();

        $this->actingAs($admin);

        $this->operationsManager($workOrder->fresh())
            ->assertCanSeeTableRecords($workOrder->operations()->get())
            ->assertTableActionHidden('assignWorkshopRouting')
            ->assertTableActionHidden('replaceWorkshopRouting')
            ->assertTableActionHidden('edit', $operation)
            ->assertTableActionHidden('manageOperationDependencies', $operation)
            ->assertTableActionHidden('delete', $operation);
    }

    public function test_a_non_admin_cannot_access_the_operations_relation_manager(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($operator);

        $this->operationsManager($workOrder)->assertForbidden();
    }

    public function test_a_supervisor_cannot_access_the_routing_assignment_interface(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($supervisor);

        $this->operationsManager($workOrder)->assertForbidden();
        $this->get("/admin/work-orders/{$workOrder->id}")->assertForbidden();
    }

    public function test_a_guest_cannot_access_the_routing_assignment_interface(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->get("/admin/work-orders/{$workOrder->id}")->assertRedirect();
    }

    public function test_an_operation_can_be_edited_and_its_dependencies_managed_before_release(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $routing = $this->activeRouting('GAM-UI-EDIT', ['A' => [], 'B' => []]);

        app(WorkOrderRoutingService::class)->assign($workOrder, $routing, $admin);

        $operations = $workOrder->operations()->get()->keyBy('code');

        $this->actingAs($admin);

        $this->operationsManager($workOrder->fresh())
            ->callTableAction('edit', $operations['A'], data: [
                'name' => 'Opération A modifiée',
            ])
            ->assertNotified('Opération modifiée')
            ->callTableAction('manageOperationDependencies', $operations['B'], data: [
                'dependencies' => [$operations['A']->getKey()],
            ])
            ->assertNotified('Dépendances enregistrées');

        $this->assertSame('Opération A modifiée', $operations['A']->fresh()->name);
        $this->assertTrue(
            $operations['B']->fresh()->dependencies()->whereKey($operations['A']->getKey())->exists(),
        );
    }

    protected function operationsManager(WorkOrder $workOrder): Testable
    {
        return Livewire::test(WorkOrderOperationsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $operations
     */
    protected function activeRouting(string $code, array $operations): WorkshopRouting
    {
        $routingService = app(WorkshopRoutingService::class);
        $operationService = app(WorkshopRoutingOperationService::class);

        $routing = $routingService->create([
            'code' => $code,
            'name' => "Gamme {$code}",
        ]);

        $created = [];

        foreach (array_keys($operations) as $operationCode) {
            $created[$operationCode] = $operationService->create($routing, [
                'code' => $operationCode,
                'name' => "Opération {$operationCode}",
                'estimated_duration_minutes' => 20,
            ]);
        }

        foreach ($operations as $operationCode => $dependencies) {
            foreach ($dependencies as $dependencyCode) {
                $created[$operationCode]->dependencies()->attach($created[$dependencyCode]->getKey());
            }
        }

        return $routingService->activate($routing);
    }
}
