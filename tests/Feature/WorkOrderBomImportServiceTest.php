<?php

namespace Tests\Feature;

use App\DTO\BomImportPlanRow;
use App\Enums\WorkOrderAuditEvent;
use App\Enums\WorkOrderBomImportStatus;
use App\Enums\WorkOrderItemStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use App\Models\WorkOrderItem;
use App\Services\BomCsvParser;
use App\Services\BomImportStorageService;
use App\Services\WorkOrderAuditService;
use App\Services\WorkOrderBomImportService;
use App\Services\WorkOrderItemService;
use App\Services\WorkOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class WorkOrderBomImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderBomImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bom_imports');

        $this->service = app(WorkOrderBomImportService::class);
    }

    public function test_a_valid_file_is_previewed_without_any_write_and_then_imported(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        [$file, $content] = $this->csv(<<<'CSV'
P-01;Poteau principal;IPE 200;S235JR;6000;10;22,4;Débit;Note
P-02;Traverse;HEA 200;S275JR;4500;5;30,5;Assemblage;
CSV);

        $preview = $this->service->preview($workOrder, $file, $admin);

        $this->assertTrue($preview->isValid());
        $this->assertSame(hash('sha256', $content), $preview->sha256);
        $this->assertSame(2, $preview->rowsTotal);
        $this->assertSame(2, $preview->rowsValid);
        $this->assertSame(0, $preview->rowsInvalid);
        $this->assertSame(2, $preview->newItemsCount);
        $this->assertSame(0, $preview->mergedRowsCount);
        $this->assertSame(15, $preview->plannedQuantityAdded);

        $this->assertSame(0, $workOrder->items()->count());
        $this->assertSame(0, WorkOrderBomImport::query()->count());
        $this->assertSame([], Storage::disk('bom_imports')->allFiles());

        $import = $this->service->import($workOrder, $file, $admin);

        $this->assertSame(WorkOrderBomImportStatus::Completed, $import->status);
        $this->assertSame($workOrder->getKey(), $import->work_order_id);
        $this->assertSame($admin->getKey(), $import->imported_by);
        $this->assertSame(2, $import->rows_total);
        $this->assertSame(2, $import->rows_valid);
        $this->assertSame(0, $import->rows_invalid);
        $this->assertSame(hash('sha256', $content), $import->sha256);
        $this->assertSame('nomenclature.csv', $import->original_filename);
        $this->assertArrayHasKey('columns', $import->column_mapping);
        $this->assertSame('mark', $import->column_mapping['columns']['mark']);

        $this->assertSame(2, $workOrder->items()->count());
        $this->assertSame(10, $workOrder->items()->where('mark', 'P-01')->value('quantity_planned'));
        $this->assertSame(5, $workOrder->items()->where('mark', 'P-02')->value('quantity_planned'));

        $this->assertTrue(Storage::disk('bom_imports')->exists($import->storage_path));
        $this->assertStringStartsWith('work-orders/'.$workOrder->getKey().'/', $import->storage_path);
        $this->assertStringEndsWith('.csv', $import->storage_path);

        $this->assertDatabaseHas('work_order_audit_logs', [
            'work_order_id' => $workOrder->getKey(),
            'event' => WorkOrderAuditEvent::BomImported->value,
        ]);
    }

    public function test_internal_compatible_duplicates_are_merged_and_only_planned_quantities_are_added(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        [$file] = $this->csv(<<<'CSV'
P-03;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;
P-03;Poteau;IPE 200;S235JR;6000;3;22,4;Débit;
CSV);

        $preview = $this->service->preview($workOrder, $file, $admin);

        $this->assertTrue($preview->isValid());
        $this->assertSame(1, $preview->newItemsCount);
        $this->assertSame(1, $preview->mergedRowsCount);
        $this->assertSame(5, $preview->plannedQuantityAdded);
        $this->assertCount(2, $preview->plans);
        $this->assertSame(BomImportPlanRow::ACTION_CREATE, $preview->plans[0]->action);
        $this->assertSame(BomImportPlanRow::ACTION_MERGE_INTERNAL, $preview->plans[1]->action);

        $this->service->import($workOrder, $file, $admin);

        $this->assertSame(1, $workOrder->items()->count());
        $this->assertSame(5, $workOrder->items()->firstOrFail()->quantity_planned);
    }

    public function test_importing_a_compatible_existing_mark_preserves_production_and_adds_planned_quantity(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $existing = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-04',
            'designation' => 'Poteau',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 5,
            'quantity_produced' => 2,
            'quantity_rejected' => 1,
            'status' => WorkOrderItemStatus::PartiallyProduced,
        ]);

        [$file] = $this->csv("P-04;Poteau;IPE 200;S235JR;6000;3;22,4;Débit;\n");

        $preview = $this->service->preview($workOrder, $file, $admin);

        $this->assertTrue($preview->isValid());
        $this->assertSame(0, $preview->newItemsCount);
        $this->assertSame(1, $preview->mergedRowsCount);
        $this->assertSame(BomImportPlanRow::ACTION_MERGE_EXISTING, $preview->plans[0]->action);
        $this->assertSame($existing->getKey(), $preview->plans[0]->existingItemId);
        $this->assertSame(8, $preview->plans[0]->resultingPlannedQuantity);

        $this->service->import($workOrder, $file, $admin);

        $existing->refresh();

        $this->assertSame(8, $existing->quantity_planned);
        $this->assertSame(2, $existing->quantity_produced);
        $this->assertSame(1, $existing->quantity_rejected);
        $this->assertSame(WorkOrderItemStatus::PartiallyProduced, $existing->status);
    }

    public function test_incompatible_internal_duplicates_reject_the_whole_file_without_writing_or_storing(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        [$file] = $this->csv(<<<'CSV'
P-05;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;
P-05;Poteau;IPE 200;S235JR;7000;3;22,4;Débit;
CSV);

        $preview = $this->service->preview($workOrder, $file, $admin);

        $this->assertFalse($preview->isValid());
        $this->assertStringContainsString('Doublon interne incompatible', $preview->errors[0]->message);

        try {
            $this->service->import($workOrder, $file, $admin);
            $this->fail('Un import avec doublon interne incompatible aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Doublon interne incompatible',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(0, $workOrder->items()->count());
        $this->assertSame(0, WorkOrderBomImport::query()->count());
        $this->assertSame([], Storage::disk('bom_imports')->allFiles());
    }

    public function test_incompatible_existing_mark_rejects_the_whole_file(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $existing = WorkOrderItem::factory()->forWorkOrder($workOrder)->create([
            'mark' => 'P-06',
            'designation' => 'Poteau',
            'profile' => 'IPE 200',
            'steel_grade' => 'S235JR',
            'length_mm' => 6000,
            'quantity_planned' => 5,
        ]);

        [$file] = $this->csv("P-06;Poteau;IPE 200;S235JR;7000;3;22,4;Débit;\n");

        $preview = $this->service->preview($workOrder, $file, $admin);

        $this->assertFalse($preview->isValid());
        $this->assertStringContainsString('caractéristiques techniques différentes', $preview->errors[0]->message);

        $this->expectException(ValidationException::class);

        $this->service->import($workOrder, $file, $admin);
    }

    public function test_one_invalid_row_rejects_the_whole_file_without_partial_writes(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        [$file] = $this->csv(<<<'CSV'
P-07;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;
P-08;Traverse;HEA 200;S275JR;4500;abc;30,5;Assemblage;
CSV);

        $preview = $this->service->preview($workOrder, $file, $admin);

        $this->assertFalse($preview->isValid());
        $this->assertSame(1, $preview->rowsInvalid);
        $this->assertSame('quantity_planned', $preview->errors[0]->column);

        try {
            $this->service->import($workOrder, $file, $admin);
            $this->fail('Un fichier avec une ligne invalide aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Ligne 3',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(0, $workOrder->items()->count());
        $this->assertSame(0, WorkOrderBomImport::query()->count());
        $this->assertSame([], Storage::disk('bom_imports')->allFiles());
    }

    public function test_the_same_hash_cannot_be_imported_twice_on_the_same_work_order(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        [$file, $content] = $this->csv("P-09;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;\n");

        $this->service->import($workOrder, $file, $admin);

        $this->assertSame(1, $workOrder->items()->count());
        $this->assertSame(1, WorkOrderBomImport::query()->count());

        $secondFile = UploadedFile::fake()->createWithContent('nomenclature.csv', $content);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('déjà été importé');

        $this->service->preview($workOrder, $secondFile, $admin);
    }

    public function test_the_same_hash_can_be_imported_on_two_different_work_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $first = WorkOrder::factory()->create();
        $second = WorkOrder::factory()->create();
        [$firstFile, $content] = $this->csv("P-10;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;\n");
        $secondFile = UploadedFile::fake()->createWithContent('bom.csv', $content);

        $this->service->import($first, $firstFile, $admin);
        $this->service->import($second, $secondFile, $admin);

        $this->assertSame(1, $first->items()->count());
        $this->assertSame(1, $second->items()->count());
        $this->assertSame(2, WorkOrderBomImport::query()->count());
    }

    public function test_a_locked_work_order_rejects_preview_and_import(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->completed()->create();
        [$file] = $this->csv("P-11;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;\n");

        $this->expectException(AuthorizationException::class);

        $this->service->preview($workOrder, $file, $admin);
    }

    public function test_a_non_admin_cannot_preview_or_import(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        [$file] = $this->csv("P-12;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;\n");

        $this->expectException(AuthorizationException::class);

        $this->service->import($workOrder, $file, $user);
    }

    public function test_a_transaction_failure_deletes_the_newly_stored_file(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        [$file] = $this->csv("P-13;Poteau;IPE 200;S235JR;6000;2;22,4;Débit;\n");

        $throwingItems = new class(app(WorkOrderService::class), app(WorkOrderAuditService::class)) extends WorkOrderItemService
        {
            public function create(WorkOrder $workOrder, array $data, ?User $actor = null): WorkOrderItem
            {
                throw new RuntimeException('forced item failure');
            }
        };

        $service = new WorkOrderBomImportService(
            app(BomCsvParser::class),
            app(BomImportStorageService::class),
            $throwingItems,
            app(WorkOrderService::class),
            app(WorkOrderAuditService::class),
        );

        try {
            $service->import($workOrder, $file, $admin);
            $this->fail('L’échec de transaction aurait dû être propagé.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced item failure', $exception->getMessage());
        }

        $this->assertSame(0, $workOrder->items()->count());
        $this->assertSame(0, WorkOrderBomImport::query()->count());
        $this->assertSame([], Storage::disk('bom_imports')->allFiles());
    }

    /**
     * @return array{0: UploadedFile, 1: string}
     */
    private function csv(string $body): array
    {
        $content = "mark;designation;profile;steel_grade;length_mm;quantity_planned;unit_weight_kg;operation_group;notes\n".$body;

        return [
            UploadedFile::fake()->createWithContent('nomenclature.csv', $content),
            $content,
        ];
    }
}
