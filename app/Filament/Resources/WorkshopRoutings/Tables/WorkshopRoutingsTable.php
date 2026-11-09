<?php

namespace App\Filament\Resources\WorkshopRoutings\Tables;

use App\Filament\Resources\WorkshopRoutings\Actions\WorkshopRoutingActions;
use App\Models\WorkshopRouting;
use App\Services\WorkshopRoutingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class WorkshopRoutingsTable
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
                TextColumn::make('operations_count')
                    ->label('Opérations')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('operations_sum_estimated_duration_minutes')
                    ->label('Durée estimée')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' min')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Dernière modification')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Statut')
                    ->options([
                        '1' => 'Actives',
                        '0' => 'Inactives',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                WorkshopRoutingActions::activate(),
                WorkshopRoutingActions::deactivate(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (WorkshopRouting $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->authorize(fn (WorkshopRouting $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->successNotification(null)
                    ->failureNotification(null)
                    ->using(function (WorkshopRouting $record): bool {
                        try {
                            app(WorkshopRoutingService::class)->delete($record);

                            Notification::make()
                                ->success()
                                ->title('Gamme supprimée')
                                ->body("La gamme {$record->code} a été supprimée.")
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
