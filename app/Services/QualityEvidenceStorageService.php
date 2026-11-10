<?php

namespace App\Services;

use App\Models\NonConformity;
use App\Models\QualityDeviation;
use App\Models\WorkOrderQualityControl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stockage des preuves qualité.
 *
 * Reprend les règles de sécurité du stockage documentaire (disque privé,
 * chemin généré serveur, MIME réel, SHA-256, nettoyage en cas d'échec) sans
 * détourner le module Documents techniques.
 */
class QualityEvidenceStorageService
{
    /**
     * @return array{disk: string, path: string, original_filename: string, extension: string, mime_type: string, size_bytes: int, sha256: string}
     */
    public function store(UploadedFile $file, WorkOrderQualityControl $control): array
    {
        $directory = trim((string) config('quality.evidence.directory', 'quality'), '/')
            .'/'.$control->work_order_id.'/'.$control->getKey();

        return $this->storeAt($file, $directory);
    }

    /**
     * @return array{disk: string, path: string, original_filename: string, extension: string, mime_type: string, size_bytes: int, sha256: string}
     */
    public function storeForNonConformity(UploadedFile $file, NonConformity $nonConformity): array
    {
        $directory = trim((string) config('quality.evidence.directory', 'quality'), '/')
            .'/non-conformities/'.$nonConformity->getKey();

        return $this->storeAt($file, $directory);
    }

    /**
     * @return array{disk: string, path: string, original_filename: string, extension: string, mime_type: string, size_bytes: int, sha256: string}
     */
    public function storeForDeviation(UploadedFile $file, QualityDeviation $deviation): array
    {
        $directory = trim((string) config('quality.evidence.directory', 'quality'), '/')
            .'/deviations/'.$deviation->getKey();

        return $this->storeAt($file, $directory);
    }

    /**
     * @return array{disk: string, path: string, original_filename: string, extension: string, mime_type: string, size_bytes: int, sha256: string}
     */
    protected function storeAt(UploadedFile $file, string $directory): array
    {
        $extension = $this->validatedExtension($file);
        $mimeType = $this->validatedMimeType($file, $extension);
        $originalFilename = $this->safeOriginalFilename($file, $extension);
        $disk = (string) config('quality.evidence.disk', 'documents');
        $filename = Str::uuid()->toString().'.'.$extension;

        try {
            $path = $file->storeAs($directory, $filename, $disk);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'Le fichier n\'a pas pu être stocké.',
            ]);
        }

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'file' => 'Le fichier n\'a pas pu être stocké.',
            ]);
        }

        try {
            $size = (int) Storage::disk($disk)->size($path);
            $sha256 = $this->hashFile($disk, $path);
        } catch (Throwable $exception) {
            $this->delete($disk, $path);
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'Le fichier stocké n\'a pas pu être vérifié.',
            ]);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $originalFilename,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'sha256' => $sha256,
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

    protected function validatedExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed = array_map('strtolower', (array) config('quality.evidence.allowed_extensions', []));

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'Extension de preuve non autorisée. Extensions acceptées : '.implode(', ', $allowed).'.',
            ]);
        }

        return $extension;
    }

    protected function validatedMimeType(UploadedFile $file, string $extension): string
    {
        $maxKb = (int) config('quality.evidence.max_size_kb', 25600);
        $size = $file->getSize();

        if ($size === false || $size <= 0) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier est vide ou sa taille ne peut pas être déterminée.',
            ]);
        }

        if ($size > ($maxKb * 1024)) {
            throw ValidationException::withMessages([
                'file' => "Le fichier dépasse la taille maximale de {$maxKb} Ko.",
            ]);
        }

        $mimeType = $file->getMimeType();

        if (! is_string($mimeType) || $mimeType === '') {
            throw ValidationException::withMessages([
                'file' => 'Le type réel du fichier ne peut pas être déterminé.',
            ]);
        }

        $allowedMimeTypes = $this->mimeTypes()[$extension] ?? [];

        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'file' => "Type de fichier non autorisé pour l'extension .{$extension} (détecté : {$mimeType}).",
            ]);
        }

        return $mimeType;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function mimeTypes(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'webp' => ['image/webp'],
            'heic' => ['image/heic', 'image/heif'],
        ];
    }

    protected function safeOriginalFilename(UploadedFile $file, string $extension): string
    {
        $name = str_replace('\\', '/', (string) $file->getClientOriginalName());
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'preuve.'.$extension;
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
}
