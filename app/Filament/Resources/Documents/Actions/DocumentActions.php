<?php

namespace App\Filament\Resources\Documents\Actions;

use App\Models\Document;
use App\Services\DocumentService;
use App\Services\DocumentVersionService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class DocumentActions
{
    public static function uploadVersion(): Action
    {
        return Action::make('uploadVersion')
            ->label('Téléverser une version')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Téléverser une nouvelle version')
            ->schema([
                TextInput::make('revision')
                    ->label('Indice / révision')
                    ->required()
                    ->maxLength(32)
                    ->default(fn (Document $record): string => app(DocumentVersionService::class)->suggestRevision($record))
                    ->helperText('Exemples : A, B, 01, A01. Un indice ne peut pas être réutilisé.'),
                FileUpload::make('file')
                    ->label('Fichier')
                    ->required()
                    ->storeFiles(false)
                    ->maxSize((int) config('documents.max_size_kb'))
                    ->helperText('Formats acceptés : '.implode(', ', (array) config('documents.allowed_extensions')).'. Taille maximale : '.round(((int) config('documents.max_size_kb')) / 1024, 1).' Mo.'),
                Textarea::make('comment')
                    ->label('Commentaire')
                    ->rows(3),
            ])
            ->action(function (Document $record, array $data): void {
                $file = is_array($data['file'] ?? null) ? Arr::first($data['file']) : ($data['file'] ?? null);

                if (! $file instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        'file' => 'Aucun fichier valide n’a été fourni.',
                    ]);
                }

                try {
                    $version = app(DocumentVersionService::class)
                        ->upload($record, $file, (string) $data['revision'], auth()->user(), $data['comment'] ?? null);

                    Notification::make()
                        ->success()
                        ->title("Version {$version->revision} téléversée")
                        ->body('La version est en brouillon. Soumettez-la à validation puis approuvez-la pour la rendre applicable.')
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Téléversement refusé')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->send();
                }
            })
            ->authorize(fn (Document $record): bool => (bool) auth()->user()?->can('uploadVersion', $record))
            ->visible(fn (Document $record): bool => (bool) auth()->user()?->can('uploadVersion', $record));
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label('Archiver')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Archiver ce document ?')
            ->modalDescription('Le document et toutes ses versions restent consultables, mais il ne sera plus applicable à la libération d’un OF.')
            ->schema([
                Textarea::make('comment')
                    ->label('Motif')
                    ->rows(3),
            ])
            ->action(function (Document $record, array $data): void {
                try {
                    app(DocumentService::class)->archive($record, auth()->user(), $data['comment'] ?? null);

                    Notification::make()
                        ->success()
                        ->title('Document archivé')
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Archivage refusé')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->send();
                }
            })
            ->authorize(fn (Document $record): bool => (bool) auth()->user()?->can('archive', $record))
            ->visible(fn (Document $record): bool => (bool) auth()->user()?->can('archive', $record));
    }
}
