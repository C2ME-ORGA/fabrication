<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class DocumentOrphanCommandTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();
    }

    public function test_orphan_files_are_reported_without_being_deleted_by_default(): void
    {
        $version = $this->uploadVersion(Document::factory()->create(), 'A');
        Storage::disk('documents')->put('documents/orphan.pdf', 'orphan');

        $this->artisan('documents:find-orphans')
            ->expectsOutputToContain('1 fichier(s) orphelin(s)')
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('documents')->exists('documents/orphan.pdf'));
        $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
    }

    public function test_orphan_files_can_be_deleted_without_touching_referenced_files(): void
    {
        $version = $this->uploadVersion(Document::factory()->create(), 'A');
        Storage::disk('documents')->put('documents/orphan.pdf', 'orphan');

        $this->artisan('documents:find-orphans', ['--delete' => true])
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('documents')->exists('documents/orphan.pdf'));
        $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
    }
}
