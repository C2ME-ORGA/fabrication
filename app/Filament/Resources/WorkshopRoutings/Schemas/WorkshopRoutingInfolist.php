<?php

namespace App\Filament\Resources\WorkshopRoutings\Schemas;

use App\Models\WorkshopRouting;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkshopRoutingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Gamme atelier')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Code'),
                        TextEntry::make('name')
                            ->label('Nom'),
                        TextEntry::make('is_active')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('operations_count')
                            ->label('Nombre d\'opérations')
                            ->state(fn (WorkshopRouting $record): int => $record->operations()->count()),
                        TextEntry::make('estimated_duration_total')
                            ->label('Durée estimée totale')
                            ->state(fn (WorkshopRouting $record): string => $record->estimatedDurationTotal().' min'),
                        TextEntry::make('updated_at')
                            ->label('Dernière modification')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
