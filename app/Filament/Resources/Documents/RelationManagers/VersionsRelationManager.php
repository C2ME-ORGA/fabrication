<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Enums\DocumentVersionStatus;
use App\Models\DocumentVersion;
use App\Services\DocumentApplicabilityService;
use App\Services\DocumentVersionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('revision')
            ->modifyQueryUsing(fn ($query) => $query->with(['uploader', 'validator']))
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('version_number')
                    ->label('N°')
                    ->sortable(),
                TextColumn::make('revision')
                    ->label('Indice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('original_filename')
                    ->label('Fichier')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('size_bytes')
                    ->label('Taille')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
                TextColumn::make('uploader.name')
                    ->label('Téléversée par')
                    ->placeholder('—'),
                TextColumn::make('validated_at')
                    ->label('Validée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('sha256')
                    ->label('SHA-256')
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(DocumentVersionStatus::options()),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('download')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (DocumentVersion $record): string => route('documents.versions.download', $record))
                    ->openUrlInNewTab()
                    ->authorize(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('download', $record))
                    ->visible(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('download', $record)),
                Action::make('submit')
                    ->label('Soumettre à validation')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->requiresConfirmation()
                    ->authorize(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('submit', $record))
                    ->visible(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('submit', $record))
                    ->action(function (DocumentVersion $record): void {
                        try {
                            app(DocumentVersionService::class)->submit($record, auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Version soumise à validation')
                                ->send();
                        } catch (ValidationException $exception) {
                            self::notifyRefusal($exception);
                        }
                    }),
                Action::make('approve')
                    ->label('Approuver')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->schema([
                        Textarea::make('comment')
                            ->label('Commentaire de validation')
                            ->rows(3),
                    ])
                    ->authorize(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('approve', $record))
                    ->visible(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('approve', $record))
                    ->action(function (DocumentVersion $record, array $data): void {
                        try {
                            $version = app(DocumentVersionService::class)
                                ->approve($record, auth()->user(), $data['comment'] ?? null);

                            $impacted = app(DocumentApplicabilityService::class)
                                ->countImpactedReleasedWorkOrders($version->document, $version->version_number);

                            Notification::make()
                                ->success()
                                ->title("Version {$version->revision} approuvée")
                                ->body($impacted > 0
                                    ? "{$impacted} OF libéré(s) utilisent encore une version antérieure. Une remise en revue explicite est nécessaire."
                                    : 'Aucun OF libéré impacté.')
                                ->send();
                        } catch (ValidationException $exception) {
                            self::notifyRefusal($exception);
                        }
                    }),
                Action::make('reject')
                    ->label('Refuser')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->schema([
                        Textarea::make('comment')
                            ->label('Motif du refus')
                            ->required()
                            ->rows(3),
                    ])
                    ->authorize(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('reject', $record))
                    ->visible(fn (DocumentVersion $record): bool => (bool) auth()->user()?->can('reject', $record))
                    ->action(function (DocumentVersion $record, array $data): void {
                        try {
                            app(DocumentVersionService::class)
                                ->reject($record, auth()->user(), $data['comment'] ?? null);

                            Notification::make()
                                ->success()
                                ->title('Version refusée')
                                ->send();
                        } catch (ValidationException $exception) {
                            self::notifyRefusal($exception);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    protected static function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
