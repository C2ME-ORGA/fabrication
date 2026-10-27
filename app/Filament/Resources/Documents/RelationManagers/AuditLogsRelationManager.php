<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Enums\DocumentAuditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Historique';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'version', 'workOrder']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('action')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state): string => DocumentAuditAction::tryFrom($state)?->getLabel() ?? $state)
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->placeholder('Système'),
                TextColumn::make('version.revision')
                    ->label('Indice')
                    ->placeholder('—'),
                TextColumn::make('workOrder.number')
                    ->label('OF')
                    ->placeholder('—'),
                TextColumn::make('comment')
                    ->label('Commentaire')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('metadata')
                    ->label('Détails')
                    ->formatStateUsing(fn (?array $state): string => $state === null ? '—' : (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Action')
                    ->options(collect(DocumentAuditAction::cases())->mapWithKeys(
                        fn (DocumentAuditAction $action): array => [$action->value => $action->getLabel()],
                    )->all()),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
