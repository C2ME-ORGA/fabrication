<?php

namespace Tests\Feature;

use App\Enums\QualityControlStatus;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityDeviationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsQualityEligibilityScenarios;
use Tests\TestCase;

/**
 * Vérification MySQL du verrouillage lors des approbations de dérogation.
 */
class QualityDeviationConcurrencyTest extends TestCase
{
    use BuildsQualityEligibilityScenarios;
    use RefreshDatabase;

    public function test_requesting_a_deviation_locks_its_exact_target_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $qualityManager = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control, $point] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => true,
        ]);
        $this->addValidatedResult($workOrder, $point, false);

        DB::transaction(function () use ($workOrder, $control, $qualityManager): void {
            app(QualityDeviationService::class)->request($workOrder, [
                'scope' => 'control',
                'work_order_quality_control_id' => $control->getKey(),
                'reason' => 'Dérogation concurrente sur une cible verrouillée.',
            ], $qualityManager);

            $this->assertRowIsLocked('work_order_quality_controls', $control->getKey());
        });
    }

    public function test_approving_a_critical_deviation_locks_its_row_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $qualityManager = User::factory()->qualityManager()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        [$control, $point] = $this->createControl($workOrder, [
            'status' => QualityControlStatus::Failed,
        ], [
            'allow_deviation' => true,
            'is_critical' => true,
        ]);
        $this->addValidatedResult($workOrder, $point, false);

        $deviation = app(QualityDeviationService::class)->request($workOrder, [
            'scope' => 'control',
            'work_order_quality_control_id' => $control->getKey(),
            'reason' => 'Dérogation critique soumise à concurrence.',
        ], $admin);

        DB::transaction(function () use ($deviation, $qualityManager): void {
            app(QualityDeviationService::class)->approve($deviation, $qualityManager, 'Première approbation.');

            $this->assertRowIsLocked('quality_deviations', $deviation->getKey());
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
