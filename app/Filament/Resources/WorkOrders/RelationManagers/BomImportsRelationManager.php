<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Enums\WorkOrderBomImportStatus;
use App\Models\WorkOrderBomImport;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BomImportsRelationManager extends RelationManager
{
    protected static string $relationship = 'bomImports';

    protected static ?string $title = 'Historique des imports';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_filename')
            ->modifyQueryUsing(fn ($query) => $query->with('importer'))
            ->defaultSort('imported_at', 'desc')
            ->columns([
                TextColumn::make('original_filename')
                    ->label('Fichier')
                    ->searchable()
                    ->limit(45),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('rows_total')
                    ->label('Lignes')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->sortable(),
                TextColumn::make('rows_valid')
                    ->label('Valides')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' '),
                TextColumn::make('rows_invalid')
                    ->label('Invalides')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' '),
                TextColumn::make('importer.name')
                    ->label('Importé par')
                    ->placeholder('—'),
                TextColumn::make('imported_at')
                    ->label('Importé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('sha256')
                    ->label('SHA-256')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(WorkOrderBomImportStatus::options()),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('download')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (WorkOrderBomImport $record): string => route('work-orders.bom-imports.download', [
                        'import' => $record,
                    ]))
                    ->openUrlInNewTab()
                    ->visible(fn (WorkOrderBomImport $record): bool => (bool) auth()->user()?->can('download', $record))
                    ->authorize(fn (WorkOrderBomImport $record): bool => (bool) auth()->user()?->can('download', $record)),
            ])
            ->toolbarActions([]);
    }
}
