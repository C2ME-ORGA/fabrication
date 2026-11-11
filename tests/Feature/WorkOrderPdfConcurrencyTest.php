<?php

namespace Tests\Feature;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderPdfGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SQLite ne fournit pas de verrouillage de ligne concurrent. Ces tests sont
 * ignorés hors MySQL.
 *
 * Une vraie exécution parallèle de deux process PHP n'est pas lancée depuis
 * PHPUnit sur cet environnement Windows. Le test vérifie le mécanisme qui
 * sérialise les générations : la transaction détient un verrou de ligne sur
 * l'OF, verrou constaté depuis une seconde connexion. Les contraintes
 * unique(scope_key, version) et unique(reference) complètent la garantie.
 */
class WorkOrderPdfConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('generated_documents');

        parent::tearDown();
    }

    public function test_generation_holds_a_row_lock_on_the_work_order_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $document = null;

        DB::beginTransaction();

        try {
            $document = app(WorkOrderPdfGenerationService::class)->generate($workOrder, $admin);

            $this->assertRowIsLocked('work_orders', $workOrder->getKey());
        } finally {
            DB::rollBack();

            if ($document !== null) {
                Storage::disk('generated_documents')->delete($document->path);
            }
        }
    }

    public function test_two_generations_produce_distinct_versions_references_and_paths_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $first = app(WorkOrderPdfGenerationService::class)->generate($workOrder, $admin);
        $firstBytes = Storage::disk('generated_documents')->get($first->path);

        $second = app(WorkOrderPdfGenerationService::class)->generate($workOrder->fresh(), $admin);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->reference, $second->reference);
        $this->assertNotSame($first->path, $second->path);

        $this->assertSame(GeneratedDocumentStatus::Superseded, $first->fresh()->status);
        $this->assertSame(GeneratedDocumentStatus::Archived, $second->fresh()->status);
        $this->assertSame($first->getKey(), $second->supersedes_id);

        $this->assertSame(2, GeneratedDocument::query()->count());

        $this->assertTrue(Storage::disk('generated_documents')->exists($first->path));
        $this->assertTrue(Storage::disk('generated_documents')->exists($second->path));
        $this->assertSame($firstBytes, Storage::disk('generated_documents')->get($first->path));
        $this->assertSame(hash('sha256', $firstBytes), $first->sha256);
        $this->assertStringStartsWith('%PDF-', $firstBytes);
    }

    public function test_unique_constraints_reject_a_duplicate_version_or_reference_on_mysql(): void
    {
        $this->skipUnlessMysql();

        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $first = app(WorkOrderPdfGenerationService::class)->generate($workOrder, $admin);

        $this->expectException(QueryException::class);

        GeneratedDocument::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'shipment_id' => null,
            'type' => $first->type,
            'scope_key' => $first->scope_key,
            'version' => $first->version,
            'reference' => $first->reference.'-bis',
            'status' => GeneratedDocumentStatus::Archived,
            'disk' => 'generated_documents',
            'path' => 'work-orders/duplicate.pdf',
            'original_filename' => 'duplicate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'template_version' => '1',
            'source_data_hash' => str_repeat('b', 64),
            'generated_by' => $admin->getKey(),
            'generated_at' => now(),
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
