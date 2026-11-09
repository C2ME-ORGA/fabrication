<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\WorkOrderOperationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite ne fournit pas de verrouillage de ligne concurrent. Ces tests sont
 * ignorés hors MySQL et restent des tests complémentaires, hors CI SQLite.
 */
class WorkOrderOperationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operation_transition_locks_the_work_order_and_operation_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = WorkOrderOperation::factory()
            ->forWorkOrder($workOrder)
            ->create(['status' => WorkshopOperationStatus::Ready]);

        app(WorkOrderOperationService::class)->start($operation, $operator);

        $this->assertRowIsLocked('work_orders', $workOrder->getKey());
        $this->assertRowIsLocked('work_order_operations', $operation->getKey());
    }

    protected function skipUnlessMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Le verrouillage concurrent MySQL ne peut pas être vérifié sur '
                .DB::connection()->getDriverName().'. Lancez ce test avec une base MySQL dédiée.',
            );
        }
    }

    protected function assertRowIsLocked(string $table, int $id): void
    {
        $connection = config('database.connections.mysql');
        config(['database.connections.mysql_probe' => $connection]);
        DB::purge('mysql_probe');

        try {
            DB::connection('mysql_probe')->statement(
                "select * from {$table} where id = ? for update nowait",
                [$id],
            );

            $this->fail("La ligne {$table}#{$id} aurait dû rester verrouillée par la transaction en cours.");
        } catch (QueryException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            $this->assertContains(
                $driverCode,
                [1205, 3572],
                "Erreur SQL inattendue pendant la vérification du verrou : {$exception->getMessage()}",
            );
        } finally {
            DB::purge('mysql_probe');
        }
    }
}
