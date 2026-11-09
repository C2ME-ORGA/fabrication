<?php

namespace Tests\Feature;

use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\BomImportsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\ItemsRelationManager;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBomImport;
use App\Services\WorkOrderBomImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderBomImportFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bom_imports');
    }

    public function test_an_admin_can_preview_and_confirm_a_bom_import(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $content = $this->csv(<<<'CSV'
P-30;Poteau;IPE 200;S235JR;6000;4;22,4;Débit;
P-31;Traverse;HEA 200;S275JR;4500;2;30,5;Assemblage;
CSV);
        $file = UploadedFile::fake()->createWithContent('nomenclature.csv', $content);

        $this->actingAs($admin);

        $component = $this->itemsManager($workOrder);

        $component->assertTableActionExists('importBom');
        $component->mountAction([
            'name' => 'importBom',
            'context' => ['table' => true],
        ]);

        $component->instance()->previewBom($file);

        $this->assertTrue($component->instance()->bomPreview['valid']);
        $this->assertSame(2, $component->instance()->bomPreview['new_items_count']);
        $this->assertFalse(
            $component->instance()->getMountedAction()->getModalSubmitAction()->isDisabled(),
        );
        $this->assertSame(0, WorkOrderBomImport::query()->count());
        $this->assertSame(0, $workOrder->items()->count());
        $this->assertSame([], Storage::disk('bom_imports')->allFiles());

        $component->instance()->confirmBomImport($file);

        $component->assertNotified('Nomenclature importée');

        $this->assertSame(2, $workOrder->items()->count());
        $this->assertSame(1, WorkOrderBomImport::query()->count());

        $import = WorkOrderBomImport::query()->firstOrFail();
        $this->assertTrue(Storage::disk('bom_imports')->exists($import->storage_path));
    }

    public function test_an_invalid_preview_is_not_imported(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();
        $content = $this->csv(<<<'CSV'
P-32;Poteau;IPE 200;S235JR;6000;abc;22,4;Débit;
CSV);
        $file = UploadedFile::fake()->createWithContent('nomenclature.csv', $content);

        $this->actingAs($admin);

        $component = $this->itemsManager($workOrder);
        $component->mountAction([
            'name' => 'importBom',
            'context' => ['table' => true],
        ]);

        $component->instance()->previewBom($file);

        $this->assertFalse($component->instance()->bomPreview['valid']);
        $this->assertNotEmpty($component->instance()->bomPreview['errors']);
        $this->assertSame(1, $component->instance()->bomPreview['rows_invalid']);
        $this->assertTrue(
            $component->instance()->getMountedAction()->getModalSubmitAction()->isDisabled(),
        );

        $component->instance()->confirmBomImport($file);

        $component->assertNotified('Action refusée');

        $this->assertSame(0, $workOrder->items()->count());
        $this->assertSame(0, WorkOrderBomImport::query()->count());
        $this->assertSame([], Storage::disk('bom_imports')->allFiles());
    }

    public function test_the_import_history_is_visible_and_the_file_is_downloadable_only_by_an_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        $file = UploadedFile::fake()->createWithContent(
            'historique.csv',
            $this->csv("P-33;Poteau;IPE 200;S235JR;6000;1;22,4;Débit;\n"),
        );

        $import = app(WorkOrderBomImportService::class)->import($workOrder, $file, $admin);

        $this->actingAs($admin);

        Livewire::test(BomImportsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ])
            ->assertCanSeeTableRecords([$import])
            ->assertSee('historique.csv')
            ->assertTableActionExists('download', record: $import);

        $this->actingAs($admin)
            ->get(route('work-orders.bom-imports.download', ['import' => $import]))
            ->assertOk()
            ->assertDownload('historique.csv');

        $this->actingAs($user)
            ->get(route('work-orders.bom-imports.download', ['import' => $import]))
            ->assertForbidden();

        Livewire::test(BomImportsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ])->assertForbidden();
    }

    public function test_a_locked_work_order_hides_the_import_action(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->completed()->create();

        $this->actingAs($admin);

        $this->itemsManager($workOrder)
            ->assertTableActionHidden('importBom');
    }

    private function csv(string $body): string
    {
        return "mark;designation;profile;steel_grade;length_mm;quantity_planned;unit_weight_kg;operation_group;notes\n".$body;
    }

    private function itemsManager(WorkOrder $workOrder): Testable
    {
        return Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $workOrder,
            'pageClass' => ViewWorkOrder::class,
        ]);
    }
}
