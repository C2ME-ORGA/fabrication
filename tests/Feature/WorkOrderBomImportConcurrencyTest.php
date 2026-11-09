<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use App\Services\WorkOrderBomImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SQLite ne démontre pas le verrouillage concurrent MySQL. Ces tests sont
 * ignorés hors MySQL et doivent être exécutés avec phpunit.mysql.xml.
 */
class WorkOrderBomImportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bom_imports');
    }

    public function test_an_import_holds_the_work_order_lock_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        app(WorkOrderBomImportService::class)->import(
            $workOrder,
            UploadedFile::fake()->createWithContent('bom.csv', $this->csv("P-40;Poteau;IPE 200;S235JR;6000;1;22,4;Débit;\n")),
            $admin,
        );

        $this->assertRowIsLocked('work_orders', $workOrder->getKey());
    }

    public function test_the_duplicate_hash_constraint_is_enforced_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $import = app(WorkOrderBomImportService::class)->import(
            $workOrder,
            UploadedFile::fake()->createWithContent('bom.csv', $this->csv("P-41;Poteau;IPE 200;S235JR;6000;1;22,4;Débit;\n")),
            $admin,
        );

        $this->expectException(QueryException::class);

        WorkOrderBomImport::factory()->forWorkOrder($workOrder)->create([
            'sha256' => $import->sha256,
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

    protected function csv(string $body): string
    {
        return "mark;designation;profile;steel_grade;length_mm;quantity_planned;unit_weight_kg;operation_group;notes\n".$body;
    }
}
