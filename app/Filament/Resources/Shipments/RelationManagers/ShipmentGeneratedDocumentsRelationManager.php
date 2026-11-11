<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Models\GeneratedDocument;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShipmentGeneratedDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'generatedDocuments';

    protected static ?string $title = 'Documents générés';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Shipment
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('generated_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('status')
                    ->label('État')
                    ->badge(),
                TextColumn::make('version')
                    ->label('Version')
                    ->numeric(decimalPlaces: 0),
                TextColumn::make('generated_at')
                    ->label('Généré le')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('sha256')
                    ->label('SHA-256')
                    ->limit(12)
                    ->tooltip(fn (GeneratedDocument $record): string => $record->sha256)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('view')
                    ->label('Voir')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (GeneratedDocument $record): string => route('generated-documents.view', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (GeneratedDocument $record): bool => auth()->user()?->can('view', $record) === true),
                Action::make('download')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (GeneratedDocument $record): string => route('generated-documents.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (GeneratedDocument $record): bool => auth()->user()?->can('download', $record) === true),
            ])
            ->toolbarActions([]);
    }
}
