<?php

namespace App\Filament\Resources\QualityControlTemplates\Tables;

use App\Enums\QualityControlType;
use App\Filament\Resources\QualityControlTemplates\Actions\QualityControlTemplateActions;
use App\Models\QualityControlTemplate;
use App\Services\QualityControlTemplateService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class QualityControlTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('control_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('currentVersion.version_number')
                    ->label('Version active')
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state)
                    ->placeholder('—'),
                TextColumn::make('is_active')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Actif' : 'Inactif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Dernière modification')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('control_type')
                    ->label('Type de contrôle')
                    ->options(QualityControlType::options()),
                SelectFilter::make('is_active')
                    ->label('Statut')
                    ->options([
                        '1' => 'Actifs',
                        '0' => 'Inactifs',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                QualityControlTemplateActions::deactivate(),
                QualityControlTemplateActions::startNewVersion(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (QualityControlTemplate $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->authorize(fn (QualityControlTemplate $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->successNotification(null)
                    ->failureNotification(null)
                    ->using(function (QualityControlTemplate $record): bool {
                        try {
                            app(QualityControlTemplateService::class)->delete($record, auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Modèle supprimé')
                                ->body("Le modèle {$record->code} a été supprimé.")
                                ->send();

                            return true;
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Suppression refusée')
                                ->body((string) collect($exception->errors())->flatten()->first())
                                ->send();

                            return false;
                        }
                    }),
            ])
            ->toolbarActions([]);
    }
}
