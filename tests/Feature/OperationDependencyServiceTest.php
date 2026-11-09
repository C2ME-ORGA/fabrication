<?php

namespace Tests\Feature;

use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Services\OperationDependencyService;
use App\Services\WorkshopRoutingOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationDependencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OperationDependencyService $service;

    protected WorkshopRoutingOperationService $operations;

    protected WorkshopRouting $routing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OperationDependencyService::class);
        $this->operations = app(WorkshopRoutingOperationService::class);
        $this->routing = WorkshopRouting::factory()->create();
    }

    public function test_a_valid_dependency_can_be_added_and_removed(): void
    {
        $drilling = $this->operation('PERCAGE');
        $cutting = $this->operation('DEBIT');

        $this->service->add($this->routing, $drilling, $cutting);

        $this->assertTrue($drilling->dependencies()->whereKey($cutting->getKey())->exists());

        $this->service->remove($this->routing, $drilling, $cutting);

        $this->assertFalse($drilling->fresh()->dependencies()->whereKey($cutting->getKey())->exists());
    }

    public function test_an_operation_cannot_depend_on_itself(): void
    {
        $operation = $this->operation('DEBIT');

        $this->expectException(ValidationException::class);

        $this->service->add($this->routing, $operation, $operation);
    }

    public function test_dependencies_between_two_routings_are_refused(): void
    {
        $operation = $this->operation('DEBIT');
        $otherRouting = WorkshopRouting::factory()->create();
        $otherOperation = WorkshopRoutingOperation::factory()->forRouting($otherRouting)->create();

        $this->expectException(ValidationException::class);

        $this->service->add($this->routing, $operation, $otherOperation);
    }

    public function test_a_duplicate_dependency_is_refused(): void
    {
        $drilling = $this->operation('PERCAGE');
        $cutting = $this->operation('DEBIT');

        $this->service->add($this->routing, $drilling, $cutting);

        $this->expectException(ValidationException::class);

        $this->service->add($this->routing, $drilling, $cutting);
    }

    public function test_a_direct_cycle_is_refused(): void
    {
        $a = $this->operation('A');
        $b = $this->operation('B');

        $this->service->add($this->routing, $a, $b);

        try {
            $this->service->add($this->routing, $b, $a);
            $this->fail('Un cycle direct doit être refusé.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cyclique', (string) collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(1, DB::table('workshop_routing_operation_dependencies')->count());
    }

    public function test_an_indirect_cycle_is_refused(): void
    {
        $a = $this->operation('A');
        $b = $this->operation('B');
        $c = $this->operation('C');

        $this->service->add($this->routing, $a, $b);
        $this->service->add($this->routing, $b, $c);

        $this->expectException(ValidationException::class);

        $this->service->add($this->routing, $c, $a);
    }

    public function test_a_complex_cycle_is_refused(): void
    {
        $a = $this->operation('A');
        $b = $this->operation('B');
        $c = $this->operation('C');
        $d = $this->operation('D');

        $this->service->add($this->routing, $a, $b);
        $this->service->add($this->routing, $b, $c);
        $this->service->add($this->routing, $c, $d);

        $this->expectException(ValidationException::class);

        $this->service->add($this->routing, $d, $a);
    }

    public function test_parallel_operations_and_multiple_dependencies_are_accepted(): void
    {
        $cutting = $this->operation('DEBIT');
        $drilling = $this->operation('PERCAGE');
        $preparation = $this->operation('PREPARATION');
        $assembly = $this->operation('ASSEMBLAGE');

        $this->service->add($this->routing, $drilling, $cutting);
        $this->service->add($this->routing, $preparation, $cutting);
        $this->service->add($this->routing, $assembly, $drilling);
        $this->service->add($this->routing, $assembly, $preparation);

        $this->assertFalse($this->service->wouldCreateCycle($this->routing, $drilling, $cutting));
        $this->assertSame(4, DB::table('workshop_routing_operation_dependencies')->count());
        $this->assertCount(2, $assembly->fresh()->dependencies);
        $this->assertTrue($this->service->graphErrors($this->routing) === []);
    }

    public function test_sync_replaces_dependencies_and_refuses_an_invalid_target_without_partial_write(): void
    {
        $a = $this->operation('A');
        $b = $this->operation('B');
        $c = $this->operation('C');

        $this->service->add($this->routing, $a, $b);

        $otherRouting = WorkshopRouting::factory()->create();
        $external = WorkshopRoutingOperation::factory()->forRouting($otherRouting)->create();

        try {
            $this->service->syncDependencies($this->routing, $a, [$c->getKey(), $external->getKey()]);
            $this->fail('Une opération externe doit être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependencies', $exception->errors());
        }

        $this->assertSame([$b->getKey()], $a->fresh()->dependencies()->pluck('workshop_routing_operations.id')->all());
    }

    public function test_sync_refuses_a_cycle_without_modifying_the_graph(): void
    {
        $a = $this->operation('A');
        $b = $this->operation('B');
        $c = $this->operation('C');

        $this->service->add($this->routing, $a, $b);
        $this->service->add($this->routing, $b, $c);

        try {
            $this->service->syncDependencies($this->routing, $c, [$a->getKey()]);
            $this->fail('Un cycle doit être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependencies', $exception->errors());
        }

        $this->assertSame([], $c->fresh()->dependencies()->pluck('workshop_routing_operations.id')->all());
        $this->assertSame(2, DB::table('workshop_routing_operation_dependencies')->count());
    }

    public function test_graph_errors_detect_cross_routing_and_self_dependencies_written_directly(): void
    {
        $a = $this->operation('A');
        $otherRouting = WorkshopRouting::factory()->create();
        $external = WorkshopRoutingOperation::factory()->forRouting($otherRouting)->create();

        DB::table('workshop_routing_operation_dependencies')->insert([
            [
                'workshop_routing_operation_id' => $a->getKey(),
                'depends_on_operation_id' => $a->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'workshop_routing_operation_id' => $a->getKey(),
                'depends_on_operation_id' => $external->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $errors = $this->service->graphErrors($this->routing);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('elle-même', implode(' ', $errors));
        $this->assertStringContainsString('appartient pas à cette gamme', implode(' ', $errors));
    }

    protected function operation(string $code): WorkshopRoutingOperation
    {
        return $this->operations->create($this->routing, [
            'code' => $code,
            'name' => "Opération {$code}",
        ]);
    }
}
