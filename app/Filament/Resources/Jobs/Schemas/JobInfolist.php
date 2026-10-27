<?php

namespace App\Filament\Resources\Jobs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Affaire')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Code affaire'),
                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge(),
                        TextEntry::make('client_name')
                            ->label('Client'),
                        TextEntry::make('name')
                            ->label('Désignation'),
                        TextEntry::make('site_address')
                            ->label('Adresse du chantier')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Créée le')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Mise à jour le')
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
