<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validation, stockage privé et suppression des fichiers de nomenclature.
 *
 * Aucun nom fourni par l'utilisateur n'est utilisé comme chemin. Le fichier
 * n'est stocké qu'au moment de la confirmation d'un import.
 */
class BomImportStorageService
{
    public function validate(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowedExtensions = array_map(
            strtolower(...),
            (array) config('bom.allowed_extensions', ['csv']),
        );

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            $this->fail('Extension de fichier non autorisée. Extensions acceptées : '.implode(', ', $allowedExtensions).'.');
        }

        $size = $file->getSize();
        $maxKb = (int) config('bom.max_size_kb', 5120);

        if ($size === false || $size <= 0) {
            $this->fail('Le fichier est vide ou sa taille ne peut pas être déterminée.');
        }

        if ($size > ($maxKb * 1024)) {
            $this->fail("Le fichier dépasse la taille maximale de {$maxKb} Ko.");
        }

        $mimeType = $file->getMimeType();
        $allowedMimeTypes = (array) config("bom.mime_types.{$extension}", []);

        if (! is_string($mimeType) || $mimeType === '') {
            $this->fail('Le type réel du fichier ne peut pas être déterminé.');
        }

        if ($allowedMimeTypes !== [] && ! in_array($mimeType, $allowedMimeTypes, true)) {
            $this->fail("Type de fichier non autorisé pour l'extension .{$extension} (détecté : {$mimeType}).");
        }
    }

    /**
     * @return array{disk: string, path: string, original_filename: string, sha256: string, size_bytes: int}
     */
    public function store(
        UploadedFile $file,
        WorkOrder $workOrder,
        string $originalFilename,
        string $expectedSha256,
    ): array {
        $this->validate($file);

        $disk = (string) config('bom.disk', 'bom_imports');
        $directory = 'work-orders/'.$workOrder->getKey();
        $filename = Str::uuid()->toString().'.csv';
        $safeOriginalFilename = $this->safeOriginalFilename($originalFilename);

        try {
            $path = $file->storeAs($directory, $filename, $disk);
        } catch (Throwable $exception) {
            report($exception);

            $this->fail("Le fichier n'a pas pu être stocké.");
        }

        if (! is_string($path) || $path === '') {
            $this->fail("Le fichier n'a pas pu être stocké.");
        }

        try {
            $size = (int) Storage::disk($disk)->size($path);
            $sha256 = $this->hashFile($disk, $path);
        } catch (Throwable $exception) {
            $this->delete($disk, $path);

            report($exception);

            $this->fail("Le fichier stocké n'a pas pu être vérifié.");
        }

        if (! hash_equals($expectedSha256, $sha256)) {
            $this->delete($disk, $path);

            $this->fail('Le hash du fichier stocké ne correspond pas au fichier téléversé.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $safeOriginalFilename,
            'sha256' => $sha256,
            'size_bytes' => $size,
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

    protected function safeOriginalFilename(string $filename): string
    {
        $name = str_replace('\\', '/', $filename);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'nomenclature.csv';
        }

        return Str::limit($name, 255, '');
    }

    protected function hashFile(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('Le fichier stocké est illisible.');
        }

        try {
            $context = hash_init('sha256');

            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    throw new \RuntimeException('La lecture du fichier stocké a échoué.');
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
