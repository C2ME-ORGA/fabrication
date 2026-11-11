<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GeneratedDocumentStorageService
{
    /**
     * @return array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int, sha256: string}
     */
    public function store(string $contents, string $directory, string $originalFilename): array
    {
        $disk = (string) config('pdf.disk', 'generated_documents');
        $directory = trim($directory, '/');
        $path = $directory.'/'.Str::uuid()->toString().'.pdf';

        try {
            $stored = Storage::disk($disk)->put($path, $contents);

            if ($stored === false) {
                throw new \RuntimeException('Le fichier PDF n\'a pas pu être écrit.');
            }

            $size = (int) Storage::disk($disk)->size($path);
        } catch (Throwable $exception) {
            report($exception);

            // put() peut avoir réussi avant que size() n'échoue : supprimer
            // le fichier déjà écrit pour ne pas laisser d'orphelin.
            if (! $this->delete($disk, $path)) {
                report(new \RuntimeException(
                    "Impossible de supprimer le fichier PDF partiel : {$path}.",
                ));
            }

            throw ValidationException::withMessages([
                'file' => "Le PDF n'a pas pu être stocké.",
            ]);
        }

        $maxKb = (int) config('pdf.max_size_kb', 25600);

        if ($maxKb > 0 && $size > $maxKb * 1024) {
            $this->delete($disk, $path);

            throw ValidationException::withMessages([
                'file' => "Le PDF dépasse la taille maximale de {$maxKb} Ko.",
            ]);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $originalFilename,
            'mime_type' => 'application/pdf',
            'size_bytes' => $size,
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function delete(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
