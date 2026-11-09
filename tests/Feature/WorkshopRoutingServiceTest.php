<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Services\WorkshopRoutingOperationService;
use App\Services\WorkshopRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkshopRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkshopRoutingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkshopRoutingService::class);
    }

    public function test_a_routing_is_created_inactive_with_normalized_code_and_operations(): void
    {
        $routing = $this->service->create([
            'code' => '  gam-001 ',
            'name' => 'Gamme charpente',
            'description' => 'Gamme standard',
            'operations' => [
                ['code' => 'debit', 'name' => 'Débit matière', 'estimated_duration_minutes' => 30],
                ['code' => 'percage', 'name' => 'Perçage', 'estimated_duration_minutes' => 20],
            ],
        ]);

        $this->assertSame('GAM-001', $routing->code);
        $this->assertFalse($routing->isActive());
        $this->assertSame(['DEBIT', 'PERCAGE'], $routing->operations()->pluck('code')->all());
        $this->assertSame([10, 20], $routing->operations()->pluck('position')->all());
    }

    public function test_a_routing_can_be_created_and_activated_with_its_operations_in_one_transaction(): void
    {
        $routing = $this->service->create([
            'code' => 'GAM-ACTIVE',
            'name' => 'Gamme active',
            'is_active' => true,
            'operations' => [
                ['code' => 'DEBIT', 'name' => 'Débit'],
            ],
        ]);

        $this->assertTrue($routing->isActive());
        $this->assertDatabaseHas('workshop_routings', [
            'id' => $routing->getKey(),
            'is_active' => true,
        ]);
    }

    public function test_a_duplicate_routing_code_is_refused_case_insensitively(): void
    {
        $this->service->create(['code' => 'GAM-001', 'name' => 'Première']);

        $this->expectException(ValidationException::class);

        $this->service->create(['code' => 'gam-001', 'name' => 'Seconde']);
    }

    public function test_a_routing_can_be_updated(): void
    {
        $routing = $this->service->create(['code' => 'GAM-001', 'name' => 'Ancienne']);

        $updated = $this->service->update($routing, [
            'code' => 'gam-002',
            'name' => 'Nouvelle',
            'description' => 'Description',
        ]);

        $this->assertSame('GAM-002', $updated->code);
        $this->assertSame('Nouvelle', $updated->name);
        $this->assertSame('Description', $updated->description);
    }

    public function test_a_routing_can_be_activated_only_when_valid(): void
    {
        $routing = $this->service->create(['code' => 'GAM-EMPTY', 'name' => 'Vide']);

        try {
            $this->service->activate($routing);
            $this->fail('Une gamme vide ne doit pas pouvoir être activée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operations', $exception->errors());
        }

        $this->assertFalse($routing->fresh()->isActive());

        app(WorkshopRoutingOperationService::class)->create($routing, [
            'code' => 'DEBIT',
            'name' => 'Débit',
        ]);

        $activated = $this->service->activate($routing);

        $this->assertTrue($activated->isActive());
    }

    public function test_activation_is_refused_when_the_dependency_graph_is_invalid(): void
    {
        $routing = $this->service->create([
            'code' => 'GAM-GRAPH',
            'name' => 'Graphe invalide',
            'operations' => [
                ['code' => 'A', 'name' => 'Opération A'],
            ],
        ]);

        $operation = $routing->operations()->firstOrFail();

        $otherRouting = $this->service->create([
            'code' => 'GAM-OTHER',
            'name' => 'Autre gamme',
            'operations' => [
                ['code' => 'B', 'name' => 'Opération B'],
            ],
        ]);

        DB::table('workshop_routing_operation_dependencies')->insert([
            'workshop_routing_operation_id' => $operation->getKey(),
            'depends_on_operation_id' => $otherRouting->operations()->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->service->activate($routing);
            $this->fail('Une gamme dont une dépendance est invalide ne doit pas être activée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependencies', $exception->errors());
        }

        $this->assertFalse($routing->fresh()->isActive());
    }

    public function test_activation_normalizes_positions_and_requires_a_mandatory_operation(): void
    {
        $routing = $this->service->create([
            'code' => 'GAM-POS',
            'name' => 'Positions',
            'operations' => [
                ['code' => 'A', 'name' => 'A'],
                ['code' => 'B', 'name' => 'B'],
            ],
        ]);

        $routing->operations()->update(['position' => 50]);
        $routing->operations()->where('code', 'B')->update(['position' => 80]);

        $activated = $this->service->activate($routing);

        $this->assertTrue($activated->isActive());
        $this->assertSame([10, 20], $activated->operations()->pluck('position')->all());

        app(WorkshopRoutingOperationService::class)->update(
            $activated->operations()->firstOrFail(),
            ['is_mandatory' => false],
        );
        app(WorkshopRoutingOperationService::class)->update(
            $activated->operations()->where('code', 'B')->firstOrFail(),
            ['is_mandatory' => false],
        );

        $this->expectException(ValidationException::class);

        $this->service->deactivate($activated);
        $this->service->activate($activated);
    }

    public function test_activation_is_refused_when_positions_are_incoherent(): void
    {
        $routing = $this->service->create([
            'code' => 'GAM-POS-INVALID',
            'name' => 'Positions invalides',
            'operations' => [
                ['code' => 'A', 'name' => 'A'],
                ['code' => 'B', 'name' => 'B'],
            ],
        ]);

        $routing->operations()->update(['position' => 10]);

        try {
            $this->service->activate($routing);
            $this->fail('Une gamme avec des positions dupliquées ne doit pas être activée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operations', $exception->errors());
        }

        $this->assertFalse($routing->fresh()->isActive());
    }

    public function test_a_failure_while_creating_operations_rolls_back_the_whole_routing(): void
    {
        try {
            $this->service->create([
                'code' => 'GAM-ROLLBACK',
                'name' => 'Gamme incomplète',
                'operations' => [
                    ['code' => 'DEBIT', 'name' => 'Débit'],
                    ['code' => 'debit', 'name' => 'Débit dupliqué'],
                ],
            ]);
            $this->fail('La création doit échouer sur le code d\'opération dupliqué.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertDatabaseMissing('workshop_routings', ['code' => 'GAM-ROLLBACK']);
        $this->assertDatabaseMissing('workshop_routing_operations', ['code' => 'DEBIT']);
    }

    public function test_a_failure_during_activation_rolls_back_routing_creation(): void
    {
        try {
            $this->service->create([
                'code' => 'GAM-EMPTY-ACTIVE',
                'name' => 'Gamme vide active',
                'is_active' => true,
            ]);
            $this->fail('Une gamme vide ne doit pas pouvoir être créée active.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operations', $exception->errors());
        }

        $this->assertDatabaseMissing('workshop_routings', ['code' => 'GAM-EMPTY-ACTIVE']);
    }

    public function test_deactivation_remains_possible_for_an_active_routing(): void
    {
        $routing = $this->service->create([
            'code' => 'GAM-DEACT',
            'name' => 'Désactivation',
            'is_active' => true,
            'operations' => [
                ['code' => 'A', 'name' => 'A'],
            ],
        ]);

        $deactivated = $this->service->deactivate($routing);

        $this->assertFalse($deactivated->isActive());
        $this->assertDatabaseHas('workshop_routings', [
            'id' => $routing->getKey(),
            'is_active' => false,
        ]);
    }

    public function test_an_unused_routing_can_be_deleted_but_a_routing_used_by_a_work_order_cannot(): void
    {
        $unused = WorkshopRouting::factory()->create();
        WorkshopRoutingOperation::factory()->forRouting($unused)->create();

        $this->service->delete($unused);

        $this->assertDatabaseMissing('workshop_routings', ['id' => $unused->getKey()]);

        $used = WorkshopRouting::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->forceFill(['workshop_routing_id' => $used->getKey()])->save();

        try {
            $this->service->delete($used);
            $this->fail('Une gamme référencée par un OF ne doit pas être supprimable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('routing', $exception->errors());
        }

        $this->assertDatabaseHas('workshop_routings', ['id' => $used->getKey()]);

        $historical = WorkshopRouting::factory()->create();
        $routingOperation = WorkshopRoutingOperation::factory()->forRouting($historical)->create();
        WorkOrderOperation::factory()->create([
            'source_routing_operation_id' => $routingOperation->getKey(),
        ]);

        try {
            $this->service->delete($historical);
            $this->fail('Une gamme ayant généré des opérations d\'OF ne doit pas être supprimable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('routing', $exception->errors());
        }

        $this->assertDatabaseHas('workshop_routings', ['id' => $historical->getKey()]);
    }
}
