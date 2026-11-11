<?php

namespace App\Http\Controllers;

use App\Models\GeneratedDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeneratedDocumentController extends Controller
{
    public function view(GeneratedDocument $generatedDocument): StreamedResponse
    {
        Gate::authorize('view', $generatedDocument);

        abort_unless(
            Storage::disk($generatedDocument->disk)->exists($generatedDocument->path),
            404,
            'Le PDF demandé est introuvable.',
        );

        return Storage::disk($generatedDocument->disk)->response(
            $generatedDocument->path,
            $generatedDocument->original_filename,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    public function download(GeneratedDocument $generatedDocument): StreamedResponse
    {
        Gate::authorize('download', $generatedDocument);

        abort_unless(
            Storage::disk($generatedDocument->disk)->exists($generatedDocument->path),
            404,
            'Le PDF demandé est introuvable.',
        );

        return Storage::disk($generatedDocument->disk)->download(
            $generatedDocument->path,
            $generatedDocument->original_filename,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
