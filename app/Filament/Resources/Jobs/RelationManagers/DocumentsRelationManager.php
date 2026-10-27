<?php

namespace App\Filament\Resources\Jobs\RelationManagers;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn ($query) => $query->with(['workOrder', 'currentVersion']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->limit(45),
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge(),
                TextColumn::make('workOrder.number')
                    ->label('OF')
                    ->placeholder('Affaire'),
                TextColumn::make('currentVersion.revision')
                    ->label('Dernière version')
                    ->placeholder('—'),
                TextColumn::make('currentVersion.status')
                    ->label('Statut version')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Catégorie')
                    ->options(DocumentCategory::options()),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(DocumentStatus::options()),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('Nouveau document')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => DocumentResource::getUrl('create', [
                        'job_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Document $record): string => DocumentResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (Document $record): string => DocumentResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Document $record): bool => (bool) auth()->user()?->can('update', $record)),
            ])
            ->toolbarActions([]);
    }
}
