<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Filament\Resources\NonConformities\NonConformityResource;
use App\Models\NonConformity;
use App\Models\WorkOrder;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Consultation des non-conformités rattachées à un OF.
 *
 * Le traitement reste dans la ressource NonConformités : cette vue ne fait
 * qu'exposer les dossiers existants et renvoyer vers leur fiche détaillée.
 */
class NonConformitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'nonConformities';

    protected static ?string $title = 'Non-conformités';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof WorkOrder
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['responsible', 'item', 'operation']))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('severity')
                    ->label('Gravité')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('responsible.name')
                    ->label('Responsable')
                    ->placeholder('—'),
                TextColumn::make('item.mark')
                    ->label('Repère')
                    ->placeholder('—'),
                TextColumn::make('operation.code')
                    ->label('Opération')
                    ->placeholder('—'),
                TextColumn::make('detected_at')
                    ->label('Déclarée le')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('due_date')
                    ->label('Échéance')
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (NonConformity $record): string => NonConformityResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (NonConformity $record): bool => (bool) auth()->user()?->can('view', $record))
                    ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('view', $record)),
            ])
            ->toolbarActions([]);
    }
}
