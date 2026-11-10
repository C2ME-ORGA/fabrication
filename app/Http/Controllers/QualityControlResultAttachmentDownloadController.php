<?php

namespace App\Http\Controllers;

use App\Models\QualityControlResultAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QualityControlResultAttachmentDownloadController extends Controller
{
    public function __invoke(QualityControlResultAttachment $attachment): StreamedResponse
    {
        Gate::authorize('download', $attachment);

        abort_unless(
            Storage::disk($attachment->disk)->exists($attachment->path),
            404,
            'La preuve demandée est introuvable.',
        );

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_filename,
        );
    }
}
