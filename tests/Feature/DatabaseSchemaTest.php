<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_queue_table_does_not_collide_with_the_business_jobs_table(): void
    {
        $this->assertSame('queue_jobs', config('queue.connections.database.table'));

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasColumn('jobs', 'code'));

        $this->assertTrue(Schema::hasTable('queue_jobs'));
        $this->assertTrue(Schema::hasColumn('queue_jobs', 'payload'));
    }

    public function test_document_tables_and_version_constraints_are_present(): void
    {
        foreach ([
            'documents',
            'document_versions',
            'work_order_document_snapshots',
            'document_audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }

        $this->assertTrue(Schema::hasColumn('documents', 'current_version_id'));
        $this->assertTrue(Schema::hasColumn('document_versions', 'version_number'));
        $this->assertTrue(Schema::hasColumn('work_order_document_snapshots', 'reason'));
    }

    public function test_work_order_status_and_priority_default_to_draft_and_normal(): void
    {
        $job = Job::factory()->create();

        DB::table('work_orders')->insert([
            'job_id' => $job->id,
            'number' => 'OF-2026-0500',
            'title' => 'Valeurs par défaut',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workOrder = WorkOrder::query()->where('number', 'OF-2026-0500')->firstOrFail();

        $this->assertSame('draft', $workOrder->getRawOriginal('status'));
        $this->assertSame('normal', $workOrder->getRawOriginal('priority'));
    }

    public function test_be_approval_columns_and_constraints_are_present(): void
    {
        foreach ([
            'responsible_id',
            'be_approved_by',
            'be_approved_at',
            'be_approval_batch_id',
            'be_approval_snapshot_id',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_orders', $column),
                "La colonne work_orders.{$column} est absente.",
            );
        }

        $this->assertTrue(Schema::hasColumn('work_order_document_snapshots', 'be_approval_batch_id'));

        // Les colonnes de version sont nullable pour représenter un document
        // applicable sans version approuvée au moment de l'approbation BE.
        $nullable = [];
        foreach (Schema::getColumns('work_order_document_snapshots') as $column) {
            $nullable[$column['name']] = (bool) $column['nullable'];
        }

        foreach (['document_version_id', 'revision', 'version_number', 'sha256'] as $name) {
            $this->assertTrue($nullable[$name], "La colonne {$name} doit être nullable.");
        }

        // Index du batch d'approbation BE.
        $batchIndexed = collect(Schema::getIndexes('work_order_document_snapshots'))
            ->contains(fn (array $index): bool => in_array('be_approval_batch_id', $index['columns'], true));
        $this->assertTrue($batchIndexed, 'La colonne be_approval_batch_id doit être indexée.');

        // Clé étrangère de rattachement du snapshot d'approbation.
        $foreignKey = collect(Schema::getForeignKeys('work_orders'))
            ->contains(fn (array $key): bool => in_array('be_approval_snapshot_id', $key['columns'], true));
        $this->assertTrue($foreignKey, 'La clé étrangère be_approval_snapshot_id doit exister.');

        // Unicité du numéro d'OF garantie par la base.
        $uniqueNumber = collect(Schema::getIndexes('work_orders'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['number']);
        $this->assertTrue($uniqueNumber, "Le numéro d'OF doit être unique en base.");
    }

    public function test_quality_release_and_shipment_schema_is_present(): void
    {
        foreach ([
            'work_order_quality_releases',
            'shipment_sequences',
            'shipments',
            'shipment_packages',
            'shipment_lines',
            'shipment_package_lines',
            'shipment_audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }

        foreach ([
            'eligible',
            'requirements_present',
            'eligibility_snapshot',
            'snapshot_hash',
            'rules_version',
            'reason',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_order_quality_releases', $column),
                "La colonne work_order_quality_releases.{$column} est absente.",
            );
        }

        $shipmentUnique = collect(Schema::getIndexes('shipments'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['number']);
        $this->assertTrue($shipmentUnique, 'Le numéro d\'expédition doit être unique en base.');

        $packageUnique = collect(Schema::getIndexes('shipment_packages'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shipment_id', 'code']);
        $this->assertTrue($packageUnique, 'Le code de colis doit être unique par expédition.');

        $lineUnique = collect(Schema::getIndexes('shipment_lines'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shipment_id', 'work_order_item_id']);
        $this->assertTrue($lineUnique, 'Une seule ligne par repère et par expédition.');

        $generatedDocumentForeignKey = collect(Schema::getForeignKeys('generated_documents'))
            ->contains(fn (array $key): bool => in_array('shipment_id', $key['columns'], true));
        $this->assertTrue($generatedDocumentForeignKey, 'La FK generated_documents.shipment_id doit exister.');
    }
}
