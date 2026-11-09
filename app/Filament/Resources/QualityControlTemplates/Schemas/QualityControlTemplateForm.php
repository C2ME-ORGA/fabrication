<?php

namespace App\Filament\Resources\QualityControlTemplates\Schemas;

use App\Enums\QualityControlType;
use App\Models\QualityControlTemplate;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QualityControlTemplateForm
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
                            ->helperText('Code unique du modèle, normalisé en majuscules.'),
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),
                        Select::make('control_type')
                            ->label('Type de contrôle')
                            ->options(QualityControlType::options())
                            ->required()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Versions et points de contrôle')
                    ->description('Le contenu métier du modèle est porté par des versions. Une version active est immuable ; toute évolution crée une nouvelle version brouillon.')
                    ->schema([
                        Placeholder::make('versions_hint')
                            ->label('Organisation')
                            ->content('Gérez les versions et leurs points depuis la fiche du modèle. Une seule version peut être active à la fois.'),
                    ])
                    ->visible(fn (?QualityControlTemplate $record): bool => $record !== null),
            ]);
    }
}
