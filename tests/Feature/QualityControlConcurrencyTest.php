<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityControlResultService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

/**
 * Vérification MySQL du verrouillage d'un résultat pendant sa validation.
 *
 * Le test est ignoré sous SQLite : le verrouillage concurrent ne peut y être
 * démontré. Il doit être lancé avec phpunit.mysql.xml avant livraison.
 */
class QualityControlConcurrencyTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    public function test_validating_a_result_locks_the_result_row_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();

        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle concurrent']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $point = $control->points()->sole();

        $result = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => true],
            $operator,
        );

        DB::transaction(function () use ($result, $supervisor): void {
            app(QualityControlResultService::class)->validate(
                $result,
                ['decision' => 'validated'],
                $supervisor,
            );

            $this->assertRowIsLocked('quality_control_results', $result->getKey());
        });
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
