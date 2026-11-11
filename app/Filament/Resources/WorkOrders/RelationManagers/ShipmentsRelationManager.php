<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Expéditions';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('N° expédition')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('État')
                    ->badge(),
                TextColumn::make('planned_ship_at')
                    ->label('Prévue le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('prepared_at')
                    ->label('Préparée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lignes'),
                TextColumn::make('packages_count')
                    ->counts('packages')
                    ->label('Colis'),
            ])
            ->headerActions([
                Action::make('createShipment')
                    ->label('Créer une expédition')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => ShipmentResource::getUrl('create'))
                    ->visible(fn (): bool => (bool) auth()->user()?->can('create', Shipment::class)),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Consulter')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Shipment $record): string => ShipmentResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
