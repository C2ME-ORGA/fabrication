<?php

namespace Tests\Feature;

use App\Models\WorkOrderOperation;
use App\Models\WorkshopRouting;
use App\Services\OperationDependencyService;
use App\Services\WorkshopRoutingOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkshopRoutingOperationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkshopRoutingOperationService $service;

    protected OperationDependencyService $dependencies;

    protected WorkshopRouting $routing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkshopRoutingOperationService::class);
        $this->dependencies = app(OperationDependencyService::class);
        $this->routing = WorkshopRouting::factory()->create();
    }

    public function test_operations_are_created_with_normalized_codes_and_incremental_positions(): void
    {
        $first = $this->service->create($this->routing, [
            'code' => ' debit ',
            'name' => 'Débit matière',
            'instructions' => 'Tronçonner',
            'estimated_duration_minutes' => 30,
        ]);

        $second = $this->service->create($this->routing, [
            'code' => 'percage',
            'name' => 'Perçage',
            'estimated_duration_minutes' => 0,
            'is_mandatory' => false,
        ]);

        $this->assertSame('DEBIT', $first->code);
        $this->assertSame(10, $first->position);
        $this->assertSame('PERCAGE', $second->code);
        $this->assertSame(20, $second->position);
        $this->assertFalse($second->is_mandatory);
    }

    public function test_an_operation_can_be_updated(): void
    {
        $operation = $this->service->create($this->routing, [
            'code' => 'DEBIT',
            'name' => 'Débit',
        ]);

        $updated = $this->service->update($operation, [
            'name' => 'Débit matière',
            'estimated_duration_minutes' => 45,
            'is_mandatory' => false,
        ]);

        $this->assertSame('Débit matière', $updated->name);
        $this->assertSame(45, $updated->estimated_duration_minutes);
        $this->assertFalse($updated->is_mandatory);
    }

    public function test_duplicate_operation_codes_are_refused_case_insensitively(): void
    {
        $this->service->create($this->routing, ['code' => 'DEBIT', 'name' => 'Débit']);

        $this->expectException(ValidationException::class);

        $this->service->create($this->routing, ['code' => 'debit', 'name' => 'Autre débit']);
    }

    public function test_a_duplicate_code_is_refused_on_update(): void
    {
        $first = $this->service->create($this->routing, ['code' => 'DEBIT', 'name' => 'Débit']);
        $second = $this->service->create($this->routing, ['code' => 'PERCAGE', 'name' => 'Perçage']);

        $this->expectException(ValidationException::class);

        $this->service->update($second, ['code' => 'debit']);
    }

    public function test_a_negative_estimated_duration_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->routing, [
            'code' => 'DEBIT',
            'name' => 'Débit',
            'estimated_duration_minutes' => -1,
        ]);
    }

    public function test_an_operation_without_dependencies_can_be_deleted_and_positions_are_normalized(): void
    {
        $first = $this->service->create($this->routing, ['code' => 'A', 'name' => 'A']);
        $second = $this->service->create($this->routing, ['code' => 'B', 'name' => 'B']);
        $third = $this->service->create($this->routing, ['code' => 'C', 'name' => 'C']);

        $this->service->delete($second);

        $this->assertDatabaseMissing('workshop_routing_operations', ['id' => $second->getKey()]);
        $this->assertSame([10, 20], $this->routing->operations()->pluck('position')->all());
        $this->assertSame([$first->getKey(), $third->getKey()], $this->routing->operations()->pluck('id')->all());
    }

    public function test_deleting_an_operation_with_dependencies_is_refused(): void
    {
        $first = $this->service->create($this->routing, ['code' => 'A', 'name' => 'A']);
        $second = $this->service->create($this->routing, ['code' => 'B', 'name' => 'B']);

        $this->dependencies->add($this->routing, $first, $second);

        try {
            $this->service->delete($first);
            $this->fail('Une opération avec dépendances ne doit pas être supprimée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }

        $this->assertDatabaseHas('workshop_routing_operations', ['id' => $first->getKey()]);
    }

    public function test_deleting_an_operation_used_to_generate_a_work_order_operation_is_refused(): void
    {
        $operation = $this->service->create($this->routing, ['code' => 'A', 'name' => 'A']);

        WorkOrderOperation::factory()->create([
            'source_routing_operation_id' => $operation->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service->delete($operation);
    }

    public function test_operations_can_be_reordered(): void
    {
        $first = $this->service->create($this->routing, ['code' => 'A', 'name' => 'A']);
        $second = $this->service->create($this->routing, ['code' => 'B', 'name' => 'B']);
        $third = $this->service->create($this->routing, ['code' => 'C', 'name' => 'C']);

        $this->service->reorder($this->routing, [
            $third->getKey(),
            $first->getKey(),
            $second->getKey(),
        ]);

        $this->assertSame(
            [$third->getKey(), $first->getKey(), $second->getKey()],
            $this->routing->operations()->pluck('id')->all(),
        );
        $this->assertSame([10, 20, 30], $this->routing->operations()->pluck('position')->all());
    }

    public function test_reorder_refuses_a_partial_operation_list(): void
    {
        $first = $this->service->create($this->routing, ['code' => 'A', 'name' => 'A']);
        $this->service->create($this->routing, ['code' => 'B', 'name' => 'B']);

        $this->expectException(ValidationException::class);

        $this->service->reorder($this->routing, [$first->getKey()]);
    }

    public function test_an_operation_can_be_moved_up_and_down(): void
    {
        $first = $this->service->create($this->routing, ['code' => 'A', 'name' => 'A']);
        $second = $this->service->create($this->routing, ['code' => 'B', 'name' => 'B']);

        $this->service->moveDown($first);

        $this->assertSame(['B', 'A'], $this->routing->operations()->pluck('code')->all());

        $this->service->moveUp($first->fresh());

        $this->assertSame(['A', 'B'], $this->routing->operations()->pluck('code')->all());
    }
}
