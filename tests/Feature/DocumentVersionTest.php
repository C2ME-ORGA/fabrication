<?php

namespace Tests\Feature;

use App\Enums\DocumentVersionStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentAuditService;
use App\Services\DocumentStorageService;
use App\Services\DocumentVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\DocumentTestHelpers;
use Tests\TestCase;

class DocumentVersionTest extends TestCase
{
    use DocumentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentStorage();
    }

    public function test_a_new_version_starts_as_a_draft_with_secure_storage_and_hash(): void
    {
        $actor = User::factory()->admin()->create();
        $document = Document::factory()->create();
        $file = $this->pdfUpload('plan-original.pdf');
        $expectedHash = hash('sha256', file_get_contents($file->getRealPath()));

        $version = app(DocumentVersionService::class)->upload($document, $file, 'A', $actor);

        $this->assertSame(DocumentVersionStatus::Draft, $version->status);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('A', $version->revision);
        $this->assertSame('plan-original.pdf', $version->original_filename);
        $this->assertSame('documents', $version->storage_disk);
        $this->assertStringStartsWith('documents/'.$document->id.'/', $version->storage_path);
        $this->assertStringNotContainsString('plan-original.pdf', $version->storage_path);
        $this->assertSame($expectedHash, $version->sha256);
        $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
        $this->assertSame($version->id, $document->fresh()->current_version_id);
        $this->assertSame($actor->id, $version->uploaded_by);
        $this->assertNull($version->validated_at);
        $this->assertDatabaseHas('document_audit_logs', [
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'action' => 'version_uploaded',
            'user_id' => $actor->id,
        ]);
    }

    public function test_multiple_versions_are_numbered_and_old_files_are_preserved(): void
    {
        $document = Document::factory()->create();

        $first = $this->uploadVersion($document, 'A');
        $second = $this->uploadVersion($document, 'B');
        $third = $this->uploadVersion($document, 'C');

        $this->assertSame([1, 2, 3], [$first->version_number, $second->version_number, $third->version_number]);
        $this->assertSame(3, $document->versions()->count());

        foreach ([$first, $second, $third] as $version) {
            $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
        }

        $this->assertSame($third->id, $document->fresh()->current_version_id);
    }

    public function test_a_revision_cannot_be_reused(): void
    {
        $document = Document::factory()->create();
        $this->uploadVersion($document, 'A');

        $this->expectException(ValidationException::class);

        $this->uploadVersion($document, 'A');
    }

    public function test_revision_suggestion_handles_letters_and_numbers(): void
    {
        $service = app(DocumentVersionService::class);
        $document = Document::factory()->create();

        $this->assertSame('A', $service->suggestRevision($document));
        $this->uploadVersion($document, 'A');
        $this->assertSame('B', $service->suggestRevision($document->fresh()));
    }

    public function test_forbidden_extension_is_rejected(): void
    {
        $document = Document::factory()->create();
        $file = UploadedFile::fake()->createWithContent('script.exe', 'MZ executable');

        $this->expectException(ValidationException::class);

        app(DocumentVersionService::class)->upload($document, $file, 'A');
    }

    public function test_oversized_file_is_rejected(): void
    {
        config(['documents.max_size_kb' => 1]);
        $document = Document::factory()->create();
        $file = $this->pdfUpload('large.pdf', str_repeat('x', 2048));

        $this->expectException(ValidationException::class);

        app(DocumentVersionService::class)->upload($document, $file, 'A');
    }

    public function test_a_malicious_original_name_does_not_control_the_storage_path(): void
    {
        $document = Document::factory()->create();
        $file = $this->pdfUpload('../../evil plan.pdf');

        $version = app(DocumentVersionService::class)->upload($document, $file, 'A');

        $this->assertStringNotContainsString('..', $version->storage_path);
        $this->assertStringNotContainsString('..', $version->original_filename);
        $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
    }

    public function test_a_pdf_with_non_pdf_content_is_rejected(): void
    {
        $document = Document::factory()->create();
        $path = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, 'plain text, not a pdf');

        try {
            $file = new UploadedFile($path, 'fake.pdf', null, null, true);

            $this->expectException(ValidationException::class);

            app(DocumentVersionService::class)->upload($document, $file, 'A');
        } finally {
            @unlink($path);
        }
    }

    public function test_a_storage_failure_does_not_create_a_version(): void
    {
        $document = Document::factory()->create();

        $this->mock(DocumentStorageService::class, function ($mock): void {
            $mock->shouldReceive('store')->andThrow(new RuntimeException('storage unavailable'));
        });

        try {
            app(DocumentVersionService::class)->upload($document, $this->pdfUpload(), 'A');
            $this->fail('Le téléversement aurait dû échouer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('storage unavailable', $exception->getMessage());
        }

        $this->assertSame(0, DocumentVersion::query()->count());
    }

    public function test_status_is_not_mass_assignable_on_a_version(): void
    {
        $version = $this->uploadVersion(Document::factory()->create(), 'A');

        $version->update(['status' => DocumentVersionStatus::Approved]);

        $this->assertSame(DocumentVersionStatus::Draft, $version->fresh()->status);
    }

    public function test_submit_then_approve_records_validator_and_timestamp(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();
        $version = $this->uploadVersion($document, 'A', $admin);

        app(DocumentVersionService::class)->submit($version, $admin);
        $version = $version->refresh();
        $this->assertSame(DocumentVersionStatus::PendingValidation, $version->status);

        $approved = app(DocumentVersionService::class)->approve($version, $admin, 'Conforme au BE');

        $this->assertSame(DocumentVersionStatus::Approved, $approved->status);
        $this->assertSame($admin->id, $approved->validated_by);
        $this->assertNotNull($approved->validated_at);
        $this->assertSame('Conforme au BE', $approved->validation_comment);
        $this->assertDatabaseHas('document_audit_logs', [
            'document_version_id' => $approved->id,
            'action' => 'version_approved',
            'user_id' => $admin->id,
        ]);
    }

    public function test_only_an_authorized_user_can_approve_or_reject(): void
    {
        $admin = User::factory()->admin()->create();
        $nonAdmin = User::factory()->create();
        $version = $this->uploadVersion(Document::factory()->create(), 'A', $admin);
        app(DocumentVersionService::class)->submit($version, $admin);
        $version->refresh();

        $this->assertTrue(Gate::forUser($admin)->allows('approve', $version));
        $this->assertTrue(Gate::forUser($admin)->allows('reject', $version));
        $this->assertFalse(Gate::forUser($nonAdmin)->allows('approve', $version));
        $this->assertFalse(Gate::forUser($nonAdmin)->allows('reject', $version));
    }

    public function test_a_draft_cannot_be_approved_without_submission(): void
    {
        $version = $this->uploadVersion(Document::factory()->create(), 'A');

        $this->expectException(ValidationException::class);

        app(DocumentVersionService::class)->approve($version);
    }

    public function test_rejection_requires_a_comment_and_records_it(): void
    {
        $admin = User::factory()->admin()->create();
        $version = $this->uploadVersion(Document::factory()->create(), 'A', $admin);
        app(DocumentVersionService::class)->submit($version, $admin);
        $version->refresh();

        try {
            app(DocumentVersionService::class)->reject($version, $admin);
            $this->fail('Un refus sans motif aurait dû être refusé.');
        } catch (ValidationException) {
            // expected
        }

        $rejected = app(DocumentVersionService::class)->reject($version, $admin, 'Indice incohérent');

        $this->assertSame(DocumentVersionStatus::Rejected, $rejected->status);
        $this->assertSame('Indice incohérent', $rejected->validation_comment);
        $this->assertNotNull($rejected->validated_at);
    }

    public function test_an_approved_version_cannot_be_changed_or_reapproved(): void
    {
        $admin = User::factory()->admin()->create();
        $version = $this->approvedVersion(Document::factory()->create(), 'A', $admin);

        $this->expectException(ValidationException::class);

        app(DocumentVersionService::class)->approve($version, $admin);
    }

    public function test_a_new_approved_version_supersedes_the_previous_one(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();

        $first = $this->approvedVersion($document, 'A', $admin);
        $second = $this->approvedVersion($document, 'B', $admin);

        $this->assertSame(DocumentVersionStatus::Superseded, $first->fresh()->status);
        $this->assertSame(DocumentVersionStatus::Approved, $second->fresh()->status);
        $this->assertSame('B', $document->fresh()->latestApprovedVersion?->revision);
        $this->assertSame($second->id, $document->fresh()->current_version_id);
        $this->assertTrue(Storage::disk('documents')->exists($first->storage_path));
        $this->assertDatabaseHas('document_audit_logs', [
            'document_version_id' => $first->id,
            'action' => 'version_superseded',
        ]);
    }

    public function test_an_older_version_cannot_be_approved_after_a_newer_approved_version(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();
        $older = $this->uploadVersion($document, 'A', $admin);
        $newer = $this->uploadVersion($document, 'B', $admin);
        app(DocumentVersionService::class)->submit($older, $admin);
        app(DocumentVersionService::class)->submit($newer, $admin);
        app(DocumentVersionService::class)->approve($newer->refresh(), $admin);

        $this->expectException(ValidationException::class);

        app(DocumentVersionService::class)->approve($older->refresh(), $admin);
    }

    public function test_a_failed_database_transaction_deletes_the_newly_stored_file(): void
    {
        $document = Document::factory()->create();

        $this->mock(DocumentAuditService::class, function ($mock): void {
            $mock->shouldReceive('log')->andThrow(new RuntimeException('audit failure'));
        });

        try {
            app(DocumentVersionService::class)->upload($document, $this->pdfUpload(), 'A');
            $this->fail('Le téléversement aurait dû échouer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failure', $exception->getMessage());
        }

        $this->assertSame(0, DocumentVersion::query()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_the_document_row_is_locked_during_version_creation_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Le verrouillage concurrent MySQL ne peut pas être vérifié sur '
                .DB::connection()->getDriverName().'. Lancez ce test avec phpunit.mysql.xml.',
            );
        }

        $document = Document::factory()->create();

        $connection = config('database.connections.mysql');
        config(['database.connections.mysql_probe' => $connection]);
        DB::purge('mysql_probe');

        // The test transaction holds the document row lock taken by upload().
        $this->uploadVersion($document, 'A');

        try {
            DB::connection('mysql_probe')->statement(
                'select * from documents where id = ? for update nowait',
                [$document->id],
            );

            $this->fail('La ligne document aurait dû être verrouillée pendant la création de version.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } finally {
            DB::purge('mysql_probe');
        }
    }

    public function test_version_storage_is_outside_the_public_directory(): void
    {
        $root = (string) config('filesystems.disks.documents.root');

        $this->assertStringNotContainsString(public_path(), $root);
        $this->assertSame(storage_path('app/private/documents'), $root);
    }
}
