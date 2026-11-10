<?php

namespace App\Filament\Resources\QualityDeviations\RelationManagers;

use App\Models\QualityDeviation;
use App\Models\QualityDeviationAttachment;
use App\Models\User;
use App\Services\QualityDeviationService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Pièces justificatives';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('original_filename')->label('Fichier')->wrap()->searchable(),
                TextColumn::make('mime_type')->label('Type')->toggleable(),
                TextColumn::make('size_bytes')
                    ->label('Taille')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1, ',', ' ').' Ko'),
                TextColumn::make('uploader.name')->label('Ajoutée par')->placeholder('—'),
                TextColumn::make('created_at')->label('Ajoutée le')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('uploadAttachment')
                    ->label('Joindre une preuve')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->color('primary')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Fichier')
                            ->required()
                            ->storeFiles(false)
                            ->maxSize((int) config('quality.evidence.max_size_kb', 25600)),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->can('attachEvidence', $this->deviation()))
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('attachEvidence', $this->deviation()))
                    ->action(function (array $data): void {
                        $file = $data['file'] ?? null;

                        if (is_array($file)) {
                            $file = Arr::first($file);
                        }

                        if (! $file instanceof UploadedFile) {
                            Notification::make()->danger()->title('Fichier manquant')->send();

                            return;
                        }

                        try {
                            app(QualityDeviationService::class)->attachEvidence(
                                $this->deviation(),
                                $file,
                                $this->actor(),
                            );

                            $this->resetTable();
                            Notification::make()->success()->title('Preuve jointe')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('downloadAttachment')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (QualityDeviationAttachment $record): string => route('filament.workshop.quality.deviation-attachments.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (QualityDeviationAttachment $record): bool => (bool) auth()->user()?->can('download', $record))
                    ->authorize(fn (QualityDeviationAttachment $record): bool => (bool) auth()->user()?->can('download', $record)),
            ])
            ->toolbarActions([]);
    }

    protected function deviation(): QualityDeviation
    {
        /** @var QualityDeviation $deviation */
        $deviation = $this->getOwnerRecord();

        return $deviation;
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        return $user;
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Ajout refusé')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
