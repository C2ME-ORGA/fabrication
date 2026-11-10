<?php

namespace App\Filament\Resources\QualityDeviations\Tables;

use App\Enums\QualityDeviationScope;
use App\Enums\QualityDeviationStatus;
use App\Models\QualityDeviation;
use App\Models\WorkOrder;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QualityDeviationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('work_order_number')
                    ->label('OF')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope')
                    ->label('Type de cible')
                    ->badge(),
                TextColumn::make('target_description')
                    ->label('Cible')
                    ->state(fn (QualityDeviation $record): string => $record->targetDescription())
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Motif')
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('requester.name')
                    ->label('Demandeur')
                    ->placeholder('—'),
                TextColumn::make('granted_at')
                    ->label('Approuvée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label('Expiration')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('work_order_id')
                    ->label('Ordre de fabrication')
                    ->options(fn (): array => WorkOrder::query()->orderByDesc('id')->pluck('number', 'id')->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(QualityDeviationStatus::options()),
                SelectFilter::make('scope')
                    ->label('Type de cible')
                    ->options(QualityDeviationScope::options()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('view', $record))
                    ->authorize(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('view', $record)),
            ])
            ->toolbarActions([]);
    }
}
