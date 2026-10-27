<?php

namespace Tests\Concerns;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentVersionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait DocumentTestHelpers
{
    protected function setUpDocumentStorage(): void
    {
        Storage::fake('documents');
    }

    protected function pdfUpload(string $name = 'plan.pdf', ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            $content ?? "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
        );
    }

    protected function uploadVersion(Document $document, string $revision = 'A', ?User $actor = null): DocumentVersion
    {
        return app(DocumentVersionService::class)->upload(
            $document,
            $this->pdfUpload("plan-{$revision}.pdf"),
            $revision,
            $actor,
        );
    }

    protected function approvedVersion(Document $document, string $revision = 'A', ?User $actor = null): DocumentVersion
    {
        $version = $this->uploadVersion($document, $revision, $actor);
        app(DocumentVersionService::class)->submit($version, $actor);
        app(DocumentVersionService::class)->approve($version, $actor, 'Validé pour test');

        return $version->refresh();
    }
}
