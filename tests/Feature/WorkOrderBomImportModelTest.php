<?php

namespace Tests\Feature;

use App\Enums\WorkOrderBomImportStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderBomImportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_import_belongs_to_a_work_order_and_an_importer(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $user = User::factory()->admin()->create();

        $import = WorkOrderBomImport::factory()
            ->forWorkOrder($workOrder)
            ->importedBy($user)
            ->create();

        $this->assertTrue($import->workOrder->is($workOrder));
        $this->assertTrue($import->importer->is($user));
        $this->assertTrue($workOrder->bomImports()->whereKey($import->getKey())->exists());
    }

    public function test_the_import_fields_are_cast_correctly(): void
    {
        $import = WorkOrderBomImport::factory()->create([
            'column_mapping' => [
                'mark' => 'mark',
                'designation' => 'designation',
            ],
            'rows_total' => 4,
            'rows_valid' => 4,
            'rows_invalid' => 0,
        ]);

        $this->assertSame(WorkOrderBomImportStatus::Completed, $import->status);
        $this->assertSame('completed', $import->getRawOriginal('status'));
        $this->assertSame(['mark' => 'mark', 'designation' => 'designation'], $import->column_mapping);
        $this->assertSame(4, $import->rows_total);
        $this->assertSame(4, $import->rows_valid);
        $this->assertSame(0, $import->rows_invalid);
        $this->assertNotNull($import->imported_at);
    }

    public function test_the_same_file_hash_cannot_be_imported_twice_on_the_same_work_order(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $hash = hash('sha256', 'contenu csv');

        WorkOrderBomImport::factory()->forWorkOrder($workOrder)->create(['sha256' => $hash]);

        $this->expectException(QueryException::class);

        WorkOrderBomImport::factory()->forWorkOrder($workOrder)->create(['sha256' => $hash]);
    }

    public function test_the_same_file_hash_may_exist_on_two_different_work_orders(): void
    {
        $hash = hash('sha256', 'contenu csv');

        $first = WorkOrderBomImport::factory()->create(['sha256' => $hash]);
        $second = WorkOrderBomImport::factory()->create(['sha256' => $hash]);

        $this->assertSame($hash, $first->sha256);
        $this->assertSame($hash, $second->sha256);
    }
}
