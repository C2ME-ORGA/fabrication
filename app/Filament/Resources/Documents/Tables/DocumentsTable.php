<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Documents\Actions\DocumentActions;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge()
                    ->sortable(),
                TextColumn::make('job_reference')
                    ->label('Affaire')
                    ->state(fn (Document $record): ?string => $record->job?->code
                        ?? $record->workOrder?->job?->code)
                    ->placeholder('—'),
                TextColumn::make('workOrder.number')
                    ->label('OF')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('currentVersion.revision')
                    ->label('Dernière version')
                    ->placeholder('—'),
                TextColumn::make('currentVersion.status')
                    ->label('Statut version')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Document')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Mise à jour')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Catégorie')
                    ->options(DocumentCategory::options()),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(DocumentStatus::options()),
                SelectFilter::make('job_id')
                    ->label('Affaire')
                    ->relationship('job', 'code')
                    ->searchable(),
                SelectFilter::make('work_order_id')
                    ->label('Ordre de fabrication')
                    ->relationship('workOrder', 'number')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DocumentActions::uploadVersion(),
                Action::make('downloadLatest')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Document $record): ?string => $record->current_version_id === null
                        ? null
                        : route('documents.versions.download', $record->current_version_id))
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => $record->current_version_id !== null
                        && (bool) auth()->user()?->can('download', $record->currentVersion)),
                DocumentActions::archive(),
            ])
            ->toolbarActions([]);
    }
}
