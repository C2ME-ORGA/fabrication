<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkOrderItemsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_items_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('work_order_items'));

        foreach ([
            'id',
            'work_order_id',
            'mark',
            'designation',
            'profile',
            'steel_grade',
            'length_mm',
            'quantity_planned',
            'quantity_produced',
            'quantity_rejected',
            'unit_weight_kg',
            'total_weight_kg',
            'operation_group',
            'status',
            'notes',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_order_items', $column),
                "La colonne work_order_items.{$column} est absente.",
            );
        }
    }

    public function test_a_mark_is_unique_per_work_order_at_database_level(): void
    {
        $indexes = collect(Schema::getIndexes('work_order_items'));

        $uniqueMark = $indexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['work_order_id', 'mark'],
        );

        $this->assertTrue($uniqueMark, 'L’index unique (work_order_id, mark) est absent.');
    }

    public function test_search_indexes_are_present(): void
    {
        $indexes = collect(Schema::getIndexes('work_order_items'));

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['work_order_id', 'status'],
        ), 'L’index (work_order_id, status) est absent.');

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => in_array('mark', $index['columns'], true),
        ), 'L’index sur mark est absent.');

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => in_array('operation_group', $index['columns'], true),
        ), 'L’index sur operation_group est absent.');
    }

    public function test_the_work_order_foreign_key_is_present(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('work_order_items'));

        $this->assertTrue($foreignKeys->contains(
            fn (array $key): bool => in_array('work_order_id', $key['columns'], true),
        ), 'La clé étrangère work_order_id est absente.');
    }

    public function test_the_item_status_defaults_to_todo(): void
    {
        $workOrder = WorkOrder::factory()->create();

        DB::table('work_order_items')->insert([
            'work_order_id' => $workOrder->getKey(),
            'mark' => 'DEFAUT-01',
            'designation' => 'Valeur par défaut',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('work_order_items')->where('mark', 'DEFAUT-01')->first();

        $this->assertSame('todo', $row->status);
        $this->assertSame(0, (int) $row->quantity_planned);
        $this->assertSame(0, (int) $row->quantity_produced);
        $this->assertSame(0, (int) $row->quantity_rejected);
    }

    public function test_work_order_bom_imports_table_has_the_expected_columns_and_constraints(): void
    {
        $this->assertTrue(Schema::hasTable('work_order_bom_imports'));

        foreach ([
            'id',
            'work_order_id',
            'original_filename',
            'storage_disk',
            'storage_path',
            'sha256',
            'column_mapping',
            'rows_total',
            'rows_valid',
            'rows_invalid',
            'imported_by',
            'imported_at',
            'status',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_order_bom_imports', $column),
                "La colonne work_order_bom_imports.{$column} est absente.",
            );
        }

        $indexes = collect(Schema::getIndexes('work_order_bom_imports'));

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['work_order_id', 'sha256'],
        ), 'L’index unique (work_order_id, sha256) est absent.');

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['work_order_id', 'status'],
        ), 'L’index (work_order_id, status) est absent.');

        $foreignKeys = collect(Schema::getForeignKeys('work_order_bom_imports'));

        $this->assertTrue($foreignKeys->contains(
            fn (array $key): bool => in_array('work_order_id', $key['columns'], true),
        ), 'La clé étrangère work_order_id est absente.');

        $this->assertTrue($foreignKeys->contains(
            fn (array $key): bool => in_array('imported_by', $key['columns'], true),
        ), 'La clé étrangère imported_by est absente.');
    }
}
