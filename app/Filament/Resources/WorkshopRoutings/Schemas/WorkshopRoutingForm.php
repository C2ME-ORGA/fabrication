<?php

namespace App\Filament\Resources\WorkshopRoutings\Schemas;

use App\Models\WorkshopRouting;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkshopRoutingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informations générales')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Code unique de la gamme, normalisé en majuscules.'),
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Opérations et dépendances')
                    ->description('Les opérations et leurs dépendances se gèrent depuis les tableaux situés sous la fiche de la gamme.')
                    ->schema([
                        Placeholder::make('operations_hint')
                            ->label('Organisation')
                            ->content('Ajoutez les opérations, réordonnez-les et définissez leurs dépendances. Une gamme doit être cohérente avant activation.'),
                    ])
                    ->visible(fn (?WorkshopRouting $record): bool => $record !== null),
            ]);
    }
}
