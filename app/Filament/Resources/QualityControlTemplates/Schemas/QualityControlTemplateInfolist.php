<?php

namespace App\Filament\Resources\QualityControlTemplates\Schemas;

use App\Models\QualityControlTemplate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QualityControlTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Modèle de contrôle')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Code'),
                        TextEntry::make('name')
                            ->label('Nom'),
                        TextEntry::make('control_type')
                            ->label('Type de contrôle')
                            ->badge(),
                        TextEntry::make('is_active')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Actif' : 'Inactif')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('versions_count')
                            ->label('Nombre de versions')
                            ->state(fn (QualityControlTemplate $record): int => $record->versions()->count()),
                        TextEntry::make('currentVersion.version_number')
                            ->label('Version courante')
                            ->placeholder('—')
                            ->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state),
                        TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
