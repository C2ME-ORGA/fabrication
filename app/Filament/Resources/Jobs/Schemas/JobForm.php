<?php

namespace App\Filament\Resources\Jobs\Schemas;

use App\Enums\JobStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Affaire')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code affaire')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Référence unique, par exemple AFF-2026-001.'),
                        TextInput::make('client_name')
                            ->label('Client')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label('Désignation')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('site_address')
                            ->label('Adresse du chantier')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('status')
                            ->label('Statut')
                            ->options(JobStatus::options())
                            ->default(JobStatus::Draft->value)
                            ->required()
                            ->native(false),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
