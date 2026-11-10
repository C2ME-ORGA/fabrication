<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Filament\Resources\QualityDeviations\QualityDeviationResource;
use App\Models\QualityDeviation;
use App\Models\WorkOrder;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Consultation des dérogations qualité rattachées à un OF.
 *
 * Les décisions d'approbation, de refus et de révocation restent dans la
 * ressource Dérogations, avec ses règles et ses contrôles de séparation.
 */
class QualityDeviationsRelationManager extends RelationManager
{
    protected static string $relationship = 'qualityDeviations';

    protected static ?string $title = 'Dérogations qualité';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof WorkOrder
            && auth()->user()?->can('viewAny', QualityDeviation::class) === true
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['requester', 'targets.control', 'targets.point', 'targets.nonConformity']))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('scope')
                    ->label('Type de cible')
                    ->badge(),
                TextColumn::make('target_description')
                    ->label('Cible')
                    ->state(fn (QualityDeviation $record): string => $record->targetDescription())
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('reason')
                    ->label('Motif')
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('requester.name')
                    ->label('Demandeur')
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->label('Expiration')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (QualityDeviation $record): string => QualityDeviationResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('view', $record))
                    ->authorize(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('view', $record)),
            ])
            ->toolbarActions([]);
    }
}
