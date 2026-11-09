<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Services\WorkOrderItemService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ces tests ne peuvent pas être validés par la CI SQLite : SQLite ne fournit
 * pas de verrouillage de ligne concurrent. Ils sont ignorés hors MySQL et
 * doivent être exécutés avec phpunit.mysql.xml sur une base dédiée.
 */
class WorkOrderItemConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_creation_holds_the_work_order_lock_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $workOrder = WorkOrder::factory()->create();

        $item = app(WorkOrderItemService::class)->create($workOrder, [
            'mark' => 'CONC-01',
            'designation' => 'Pièce concurrente',
            'quantity_planned' => 1,
        ]);

        $this->assertSame(1, $workOrder->items()->count());

        $this->assertRowIsLocked('work_orders', $workOrder->getKey());
    }

    public function test_production_update_holds_the_item_lock_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $workOrder = WorkOrder::factory()->create();

        $service = app(WorkOrderItemService::class);

        $item = $service->create($workOrder, [
            'mark' => 'CONC-02',
            'designation' => 'Pièce concurrente',
            'quantity_planned' => 2,
        ]);

        $item = $service->recordProduction($item, 1, 0);

        $this->assertSame(1, $item->quantity_produced);

        $this->assertRowIsLocked('work_order_items', $item->getKey());
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
