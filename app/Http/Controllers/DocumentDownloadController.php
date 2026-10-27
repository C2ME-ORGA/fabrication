<?php

namespace App\Http\Controllers;

use App\Enums\DocumentAuditAction;
use App\Models\DocumentVersion;
use App\Services\DocumentAuditService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function __invoke(DocumentVersion $version, DocumentAuditService $audit): StreamedResponse
    {
        Gate::authorize('download', $version);

        $version->loadMissing('document');

        abort_unless(
            Storage::disk($version->storage_disk)->exists($version->storage_path),
            404,
            'Le fichier demandé est introuvable.',
        );

        $audit->log(
            DocumentAuditAction::VersionDownloaded,
            $version->document,
            $version,
            actor: auth()->user(),
            metadata: ['revision' => $version->revision],
        );

        return Storage::disk($version->storage_disk)->download(
            $version->storage_path,
            $version->downloadFilename(),
        );
    }
}
