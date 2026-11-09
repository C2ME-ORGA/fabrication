<?php

namespace App\Http\Controllers;

use App\Models\WorkOrderBomImport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkOrderBomImportDownloadController extends Controller
{
    public function __invoke(WorkOrderBomImport $import): StreamedResponse
    {
        Gate::authorize('download', $import);

        abort_unless(
            Storage::disk($import->storage_disk)->exists($import->storage_path),
            404,
            'Le fichier de nomenclature demandé est introuvable.',
        );

        return Storage::disk($import->storage_disk)->download(
            $import->storage_path,
            $import->original_filename,
        );
    }
}
