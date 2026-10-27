<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\WorkOrder;
use App\Services\WorkOrderNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WorkOrderNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(WorkOrderNumberGenerator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_numbers_are_sequential_and_formatted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));

        $this->assertSame('OF-2026-0001', $this->generator->nextNumber());
        $this->assertSame('OF-2026-0002', $this->generator->nextNumber());
        $this->assertSame('OF-2026-0003', $this->generator->nextNumber());
    }

    public function test_each_year_has_its_own_counter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00'));
        $this->assertSame('OF-2026-0001', $this->generator->nextNumber());

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:30:00'));
        $this->assertSame('OF-2027-0001', $this->generator->nextNumber());
        $this->assertSame('OF-2027-0002', $this->generator->nextNumber());

        Carbon::setTestNow(Carbon::parse('2027-06-01 12:00:00'));
        $this->assertSame('OF-2027-0003', $this->generator->nextNumber());

        $this->assertDatabaseHas('work_order_sequences', ['year' => 2026, 'last_number' => 1]);
        $this->assertDatabaseHas('work_order_sequences', ['year' => 2027, 'last_number' => 3]);
    }

    public function test_a_rolled_back_transaction_does_not_consume_a_number(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));

        DB::beginTransaction();
        $this->assertSame('OF-2026-0001', $this->generator->nextNumber());
        DB::rollBack();

        $this->assertSame('OF-2026-0001', $this->generator->nextNumber());
    }

    public function test_an_invalid_year_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->generator->nextNumber(1999);
    }

    public function test_database_rejects_duplicate_work_order_numbers(): void
    {
        $job = Job::factory()->create();
        $first = WorkOrder::factory()->create(['job_id' => $job->id]);

        $this->expectException(QueryException::class);

        DB::table('work_orders')->insert([
            'job_id' => $job->id,
            'number' => $first->number,
            'title' => 'Doublon',
            'status' => 'draft',
            'priority' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * True concurrency can only be verified on MySQL/MariaDB. The SQLite test
     * driver serialises writes and does not implement row-level locks, so this
     * test is skipped there.
     */
    public function test_the_sequence_row_is_locked_for_update_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Le verrouillage concurrent MySQL ne peut pas être vérifié sur '
                .DB::connection()->getDriverName().'. Lancez ce test avec une base MySQL dédiée.',
            );
        }

        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));

        $connection = config('database.connections.mysql');
        config(['database.connections.mysql_probe' => $connection]);
        DB::purge('mysql_probe');

        // The generator runs inside the test transaction and holds the
        // `SELECT ... FOR UPDATE` lock on the 2026 counter row.
        $this->assertSame('OF-2026-0001', $this->generator->nextNumber());

        try {
            DB::connection('mysql_probe')->statement(
                'select * from work_order_sequences where `year` = ? for update nowait',
                [2026],
            );

            $this->fail('La ligne de compteur aurait dû être verrouillée par la transaction en cours.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } finally {
            DB::purge('mysql_probe');
        }
    }
}
