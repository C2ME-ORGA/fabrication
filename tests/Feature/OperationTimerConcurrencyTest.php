<?php

namespace Tests\Feature;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests complémentaires MySQL uniquement. SQLite ne vérifie pas les
 * verrouillages de ligne concurrents ; ces tests sont ignorés en CI SQLite.
 */
class OperationTimerConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_timer_start_locks_the_work_order_operation_and_session_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder);

        $entry = app(OperationTimerService::class)->start($operation, $operator);

        $this->assertRowIsLocked('work_orders', $workOrder->getKey());
        $this->assertRowIsLocked('work_order_operations', $operation->getKey());
        $this->assertRowIsLocked('operation_time_entries', $entry->getKey());
    }

    public function test_the_database_rejects_a_second_active_session_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $operation = $this->operation($workOrder);

        app(OperationTimerService::class)->start($operation, $operator);

        $this->expectException(QueryException::class);

        OperationTimeEntry::query()->create([
            'work_order_operation_id' => $operation->getKey(),
            'user_id' => $operator->getKey(),
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => null,
            'entry_type' => OperationTimeEntryType::Timer,
            'created_by' => $operator->getKey(),
        ]);
    }

    protected function operation(WorkOrder $workOrder): WorkOrderOperation
    {
        return WorkOrderOperation::factory()->forWorkOrder($workOrder)->create([
            'status' => WorkshopOperationStatus::InProgress,
            'started_at' => now(),
        ]);
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
