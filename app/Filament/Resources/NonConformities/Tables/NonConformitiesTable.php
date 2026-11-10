<?php

namespace App\Filament\Resources\NonConformities\Tables;

use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Models\NonConformity;
use App\Models\WorkOrder;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NonConformitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('work_order_number')
                    ->label('OF')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('severity')
                    ->label('Gravité')
                    ->badge()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('responsible.name')
                    ->label('Responsable')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('detected_at')
                    ->label('Déclarée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Échéance')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label('Clôturée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('work_order_id')
                    ->label('Ordre de fabrication')
                    ->options(fn (): array => WorkOrder::query()
                        ->orderByDesc('id')
                        ->pluck('number', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('severity')
                    ->label('Gravité')
                    ->options(NonConformitySeverity::options()),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(NonConformityStatus::options()),
                SelectFilter::make('responsible_id')
                    ->label('Responsable')
                    ->relationship('responsible', 'name')
                    ->searchable(),
                Filter::make('detected_period')
                    ->label('Période de déclaration')
                    ->form([
                        DatePicker::make('detected_from')->label('Du'),
                        DatePicker::make('detected_until')->label('Au'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['detected_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('detected_at', '>=', $date))
                        ->when($data['detected_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('detected_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (NonConformity $record): bool => (bool) auth()->user()?->can('view', $record))
                    ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('view', $record)),
            ])
            ->toolbarActions([]);
    }
}
