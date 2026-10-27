<?php

namespace App\Filament\Resources\Jobs\Tables;

use App\Enums\JobStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Code affaire')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Désignation')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('work_orders_count')
                    ->label('OF')
                    ->counts('workOrders'),
                TextColumn::make('updated_at')
                    ->label('Mise à jour')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(JobStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription("La suppression n'est possible que si l'affaire ne contient aucun ordre de fabrication."),
            ])
            ->toolbarActions([]);
    }
}
