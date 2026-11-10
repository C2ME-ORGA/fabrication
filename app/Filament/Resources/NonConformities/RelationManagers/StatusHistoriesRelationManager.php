<?php

namespace App\Filament\Resources\NonConformities\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Historique';

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
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('from_status')
                    ->label('De')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('to_status')
                    ->label('Vers')
                    ->badge(),
                TextColumn::make('actor.name')
                    ->label('Auteur')
                    ->placeholder('—'),
                TextColumn::make('comment')
                    ->label('Commentaire')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
