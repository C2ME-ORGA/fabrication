<?php

namespace App\Filament\Resources\Jobs\RelationManagers;

use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WorkOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'workOrders';

    protected static ?string $title = 'Ordres de fabrication';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Numéro')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Désignation')
                    ->searchable()
                    ->limit(45),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('priority')
                    ->label('Priorité')
                    ->badge(),
                TextColumn::make('planned_start_at')
                    ->label('Début prévu')
                    ->date('d/m/Y'),
                TextColumn::make('planned_end_at')
                    ->label('Fin prévue')
                    ->date('d/m/Y'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(WorkOrderStatus::options()),
                SelectFilter::make('priority')
                    ->label('Priorité')
                    ->options(WorkOrderPriority::options()),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('Nouvel ordre de fabrication')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => WorkOrderResource::getUrl('create', [
                        'job_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (WorkOrder $record): string => WorkOrderResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
