<?php

namespace App\Filament\Resources\QualityControlTemplateVersions\Tables;

use App\Enums\QualityControlTemplateVersionStatus;
use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use App\Filament\Resources\QualityControlTemplateVersions\Actions\QualityControlTemplateVersionActions;
use App\Models\QualityControlTemplateVersion;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QualityControlTemplateVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('template.code')
                    ->label('Modèle')
                    ->searchable()
                    ->sortable()
                    ->url(fn (QualityControlTemplateVersion $record): string => QualityControlTemplateResource::getUrl('view', ['record' => $record->quality_control_template_id])),
                TextColumn::make('version_number')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => 'v'.$state)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('points_count')
                    ->label('Points')
                    ->numeric(),
                TextColumn::make('change_note')
                    ->label('Motif de changement')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('activated_at')
                    ->label('Activée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(QualityControlTemplateVersionStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                QualityControlTemplateVersionActions::activate(),
                QualityControlTemplateVersionActions::deleteDraft(),
            ])
            ->toolbarActions([]);
    }
}
