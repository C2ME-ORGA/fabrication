<?php

namespace App\Console\Commands;

use App\Models\GeneratedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FindOrphanGeneratedDocumentFiles extends Command
{
    protected $signature = 'generated-documents:find-orphans {--delete : Supprime les fichiers orphelins au lieu de les lister}';

    protected $description = 'Liste ou supprime les PDF générés sans enregistrement, et signale les enregistrements sans fichier';

    public function handle(): int
    {
        $disk = (string) config('pdf.disk', 'generated_documents');

        if (! Storage::disk($disk)->exists('/')) {
            $this->components->warn("Le disque {$disk} est vide ou introuvable.");

            return self::SUCCESS;
        }

        // Seuls les enregistrements du disque configuré sont comparés : aucun
        // autre disque n'est parcouru ni modifié.
        $documents = GeneratedDocument::query()
            ->where('disk', $disk)
            ->get(['id', 'reference', 'path']);

        $referenced = $documents->pluck('path')->flip();

        $orphans = collect(Storage::disk($disk)->allFiles())
            ->reject(fn (string $path): bool => $referenced->has($path))
            ->values();

        $missing = $documents
            ->reject(fn (GeneratedDocument $document): bool => Storage::disk($disk)->exists($document->path))
            ->values();

        if ($missing->isNotEmpty()) {
            $this->components->error($missing->count().' enregistrement(s) sans fichier détecté(s).');

            foreach ($missing as $document) {
                $this->line(" - #{$document->getKey()} {$document->reference} : {$document->path}");
            }
        }

        if ($orphans->isEmpty()) {
            $this->components->info('Aucun fichier orphelin détecté.');

            return $missing->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        $this->components->warn($orphans->count().' fichier(s) orphelin(s) détecté(s).');

        foreach ($orphans as $path) {
            $this->line(" - {$path}");
        }

        if (! $this->option('delete')) {
            $this->components->info('Relancez avec --delete pour supprimer ces fichiers.');

            return $missing->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($orphans as $path) {
            try {
                if (Storage::disk($disk)->delete($path)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        $this->components->info("{$deleted} fichier(s) supprimé(s), {$failed} échec(s).");

        return $failed === 0 && $missing->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
