<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShipmentAuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Journal d\'expédition';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Shipment
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('event')
                    ->label('Événement')
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->placeholder('—'),
                TextColumn::make('from_status')
                    ->label('De')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (ShipmentStatus::tryFrom($state)?->getLabel() ?? $state))
                    ->toggleable(),
                TextColumn::make('to_status')
                    ->label('Vers')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (ShipmentStatus::tryFrom($state)?->getLabel() ?? $state))
                    ->toggleable(),
                TextColumn::make('comment')
                    ->label('Commentaire')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
