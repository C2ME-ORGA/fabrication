<?php

namespace App\Filament\Resources\QualityControlTemplateVersions\Schemas;

use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use App\Models\QualityControlTemplateVersion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QualityControlTemplateVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Version du modèle')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('template.code')
                            ->label('Modèle')
                            ->url(fn (QualityControlTemplateVersion $record): string => QualityControlTemplateResource::getUrl('view', ['record' => $record->quality_control_template_id])),
                        TextEntry::make('version_number')
                            ->label('Version')
                            ->formatStateUsing(fn (int $state): string => 'v'.$state),
                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge(),
                        TextEntry::make('points_count')
                            ->label('Nombre de points')
                            ->state(fn (QualityControlTemplateVersion $record): int => $record->points()->count()),
                        TextEntry::make('change_note')
                            ->label('Motif de changement')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('activated_by')
                            ->label('Activée par')
                            ->state(fn (QualityControlTemplateVersion $record): string => $record->activatedBy?->name ?? '—'),
                        TextEntry::make('activated_at')
                            ->label('Activée le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('retired_at')
                            ->label('Retirée le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
