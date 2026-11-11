<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Models\WorkOrderQualityRelease;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class QualityReleasesRelationManager extends RelationManager
{
    protected static string $relationship = 'qualityReleases';

    protected static ?string $title = 'Libérations qualité';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->canViewQualityHistory() === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('released_at', 'desc')
            ->columns([
                TextColumn::make('released_at')
                    ->label('Libérée le')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('phase')
                    ->label('Phase')
                    ->badge(),
                TextColumn::make('reason')
                    ->label('Motif')
                    ->badge(),
                IconColumn::make('eligible')
                    ->label('Éligible')
                    ->boolean(),
                IconColumn::make('requirements_present')
                    ->label('Exigences')
                    ->boolean(),
                TextColumn::make('releaser.name')
                    ->label('Libérée par')
                    ->placeholder('—'),
                TextColumn::make('snapshot_hash')
                    ->label('Empreinte')
                    ->limit(12)
                    ->tooltip(fn (WorkOrderQualityRelease $record): string => $record->snapshot_hash),
                TextColumn::make('comment')
                    ->label('Commentaire')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
