<?php

namespace Tests\Feature;

use App\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneratedDocumentOrphanCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('generated_documents');
        Storage::fake('documents');
        config(['pdf.disk' => 'generated_documents']);
    }

    public function test_orphan_files_are_reported_without_being_deleted_by_default(): void
    {
        $document = $this->archivedDocument('work-orders/1/work_order_pdf/kept.pdf');
        Storage::disk('generated_documents')->put('work-orders/1/work_order_pdf/orphan.pdf', 'orphan');

        $this->artisan('generated-documents:find-orphans')
            ->expectsOutputToContain('1 fichier(s) orphelin(s)')
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('generated_documents')->exists('work-orders/1/work_order_pdf/orphan.pdf'));
        $this->assertTrue(Storage::disk('generated_documents')->exists($document->path));
    }

    public function test_delete_mode_removes_orphans_and_keeps_referenced_files(): void
    {
        $document = $this->archivedDocument('work-orders/1/work_order_pdf/kept.pdf');
        Storage::disk('generated_documents')->put('work-orders/1/work_order_pdf/orphan.pdf', 'orphan');

        $this->artisan('generated-documents:find-orphans', ['--delete' => true])
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('generated_documents')->exists('work-orders/1/work_order_pdf/orphan.pdf'));
        $this->assertTrue(Storage::disk('generated_documents')->exists($document->path));
        $this->assertDatabaseHas('generated_documents', ['id' => $document->getKey()]);
    }

    public function test_records_without_a_file_are_reported_and_make_the_command_fail(): void
    {
        $this->archivedDocument('work-orders/1/work_order_pdf/missing.pdf', writeFile: false);

        $this->artisan('generated-documents:find-orphans')
            ->expectsOutputToContain('1 enregistrement(s) sans fichier')
            ->assertFailed();
    }

    public function test_other_disks_are_not_scanned_or_deleted(): void
    {
        Storage::disk('documents')->put('documents/other.pdf', 'other');

        $this->archivedDocument('work-orders/1/work_order_pdf/kept.pdf');

        $this->artisan('generated-documents:find-orphans', ['--delete' => true])
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('documents')->exists('documents/other.pdf'));
    }

    protected function archivedDocument(string $path, bool $writeFile = true): GeneratedDocument
    {
        if ($writeFile) {
            Storage::disk('generated_documents')->put($path, '%PDF-1.4');
        }

        return GeneratedDocument::factory()->create([
            'disk' => 'generated_documents',
            'path' => $path,
        ]);
    }
}
