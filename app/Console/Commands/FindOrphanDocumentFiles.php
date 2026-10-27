<?php

namespace App\Console\Commands;

use App\Models\DocumentVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FindOrphanDocumentFiles extends Command
{
    protected $signature = 'documents:find-orphans {--delete : Supprime les fichiers orphelins au lieu de les lister}';

    protected $description = 'Liste ou supprime les fichiers du disque documents qui ne sont référencés par aucune version';

    public function handle(): int
    {
        $disk = (string) config('documents.disk');

        if (! Storage::disk($disk)->exists('/')) {
            $this->components->warn("Le disque {$disk} est vide ou introuvable.");

            return self::SUCCESS;
        }

        $referenced = DocumentVersion::query()
            ->where('storage_disk', $disk)
            ->pluck('storage_path')
            ->flip();

        $orphans = collect(Storage::disk($disk)->allFiles())
            ->reject(fn (string $path): bool => $referenced->has($path))
            ->values();

        if ($orphans->isEmpty()) {
            $this->components->info('Aucun fichier orphelin détecté.');

            return self::SUCCESS;
        }

        $this->components->warn($orphans->count().' fichier(s) orphelin(s) détecté(s).');

        foreach ($orphans as $path) {
            $this->line(" - {$path}");
        }

        if (! $this->option('delete')) {
            $this->components->info('Relancez avec --delete pour supprimer ces fichiers.');

            return self::SUCCESS;
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

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
