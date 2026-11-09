<?php

namespace App\Filament\Workshop\Resources\WorkshopOperations\Tables;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkshopOperationStatus;
use App\Filament\Workshop\Actions\WorkshopOperationActions;
use App\Models\Job;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimeAggregationService;
use App\Support\DurationFormatter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkshopOperationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('workOrder.number')
                    ->label('OF')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('workOrder.job.code')
                    ->label('Affaire')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('workOrder.job.name')
                    ->label('Chantier')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Opération')
                    ->searchable()
                    ->wrap()
                    ->limit(40),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('workOrder.priority')
                    ->label('Priorité')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('estimated_duration_minutes')
                    ->label('Estimé')
                    ->state(fn (WorkOrderOperation $record): string => $record->estimated_duration_minutes === null
                        ? '—'
                        : DurationFormatter::formatMinutes($record->estimated_duration_minutes))
                    ->toggleable(),
                TextColumn::make('real_duration')
                    ->label('Temps réel')
                    ->state(fn (WorkOrderOperation $record): string => DurationFormatter::format(
                        app(OperationTimeAggregationService::class)
                            ->realSecondsFromEntries($record->timeEntries),
                    ))
                    ->toggleable(),
                TextColumn::make('active_operators')
                    ->label('Opérateurs actifs')
                    ->state(fn (WorkOrderOperation $record): int => $record->timeEntries
                        ->whereNull('ended_at')
                        ->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('last_activity')
                    ->label('Dernière activité')
                    ->state(fn (WorkOrderOperation $record): mixed => $record->lastStatusAudit?->created_at
                        ?? $record->updated_at)
                    ->dateTime('d/m/Y H:i')
                    ->timezone('Europe/Paris')
                    ->sortable(['updated_at']),
                TextColumn::make('last_actor')
                    ->label('Dernier intervenant')
                    ->state(fn (WorkOrderOperation $record): ?string => $record->lastStatusAudit?->user?->name)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(WorkshopOperationStatus::options()),
                SelectFilter::make('work_order_id')
                    ->label('OF')
                    ->options(fn (): array => WorkOrder::query()
                        ->whereIn('status', self::activeWorkOrderStatuses())
                        ->orderBy('number')
                        ->pluck('number', 'id')
                        ->all())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return filled($value)
                            ? $query->where('work_order_id', $value)
                            : $query;
                    }),
                SelectFilter::make('job_id')
                    ->label('Affaire')
                    ->options(fn (): array => Job::query()
                        ->whereHas('workOrders', fn (Builder $query): Builder => $query->whereIn(
                            'status',
                            self::activeWorkOrderStatuses(),
                        ))
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return filled($value)
                            ? $query->whereHas('workOrder', fn (Builder $query): Builder => $query->where('job_id', $value))
                            : $query;
                    }),
                SelectFilter::make('code')
                    ->label('Opération')
                    ->options(fn (): array => WorkOrderOperation::query()
                        ->whereHas('workOrder', fn (Builder $query): Builder => $query->whereIn(
                            'status',
                            self::activeWorkOrderStatuses(),
                        ))
                        ->distinct()
                        ->orderBy('code')
                        ->pluck('code', 'code')
                        ->all())
                    ->searchable(),
                Filter::make('blocked')
                    ->label('Bloquées')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', WorkshopOperationStatus::Blocked->value)),
                Filter::make('in_progress')
                    ->label('En cours')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', WorkshopOperationStatus::InProgress->value)),
                Filter::make('available')
                    ->label('Disponibles')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', WorkshopOperationStatus::Ready->value)),
                Filter::make('active_timer')
                    ->label('Chrono actif')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('activeTimeEntries')),
            ])
            ->recordActions([
                ViewAction::make(),
                WorkshopOperationActions::start(),
                WorkshopOperationActions::pause(),
                WorkshopOperationActions::resume(),
                WorkshopOperationActions::block(),
                WorkshopOperationActions::unblock(),
                WorkshopOperationActions::complete(),
                WorkshopOperationActions::cancel(),
                WorkshopOperationActions::startTimer(),
                WorkshopOperationActions::stopTimer(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, string>
     */
    protected static function activeWorkOrderStatuses(): array
    {
        return [
            WorkOrderStatus::Released->value,
            WorkOrderStatus::InProgress->value,
            WorkOrderStatus::QualityCheck->value,
        ];
    }
}
