<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class DocumentDownloadTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();
    }

    public function test_an_admin_can_download_a_version_through_the_controlled_route(): void
    {
        $admin = User::factory()->admin()->create();
        $version = $this->approvedVersion(Document::factory()->create(), 'A', $admin);

        $this->actingAs($admin)
            ->get(route('documents.versions.download', $version))
            ->assertOk()
            ->assertDownload($version->downloadFilename());

        $this->assertDatabaseHas('document_audit_logs', [
            'document_version_id' => $version->id,
            'action' => 'version_downloaded',
            'user_id' => $admin->id,
        ]);
    }

    public function test_a_non_admin_cannot_download_a_version(): void
    {
        $user = User::factory()->create();
        $version = $this->approvedVersion(Document::factory()->create(), 'A');

        $this->actingAs($user)
            ->get(route('documents.versions.download', $version))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $version = $this->approvedVersion(Document::factory()->create(), 'A');

        $this->get(route('documents.versions.download', $version))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_superseded_version_remains_downloadable(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();
        $first = $this->approvedVersion($document, 'A', $admin);
        $this->approvedVersion($document, 'B', $admin);

        $this->actingAs($admin)
            ->get(route('documents.versions.download', $first))
            ->assertOk();
    }

    public function test_the_download_route_is_not_served_from_the_public_directory(): void
    {
        $root = (string) config('filesystems.disks.documents.root');

        $this->assertStringStartsWith(storage_path('app/private/documents'), $root);
        $this->assertStringNotContainsString(public_path(), $root);
        $this->assertTrue(Storage::disk('documents')->exists('/'));
    }
}
