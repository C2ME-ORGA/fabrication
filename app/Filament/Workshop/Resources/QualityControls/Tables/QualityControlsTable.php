<?php

namespace App\Filament\Workshop\Resources\QualityControls\Tables;

use App\DTO\QualityControlProgress;
use App\Enums\QualityControlStatus;
use App\Enums\WorkOrderPriority;
use App\Filament\Workshop\Resources\QualityControls\Actions\QualityControlActions;
use App\Filament\Workshop\Resources\QualityControls\QualityControlResource;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Services\QualityControlEvaluationService;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QualityControlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('required_by_at', 'asc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('workOrder.number')
                    ->label('OF')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('workOrder.priority')
                    ->label('Priorité')
                    ->badge()
                    ->sortable(),
                TextColumn::make('template_name')
                    ->label('Contrôle')
                    ->description(fn (WorkOrderQualityControl $record): string => $record->template_code.' / v'.$record->template_version_number)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('operation.code')
                    ->label('Opération')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('scope')
                    ->label('Portée')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('progress')
                    ->label('Avancement')
                    ->state(fn (WorkOrderQualityControl $record): string => app(QualityControlEvaluationService::class)->summary($record))
                    ->badge()
                    ->color(fn (WorkOrderQualityControl $record): string => match ($record->status) {
                        QualityControlStatus::Passed => 'success',
                        QualityControlStatus::Failed => 'danger',
                        QualityControlStatus::Cancelled => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('awaiting_validation')
                    ->label('Points à valider')
                    ->state(fn (WorkOrderQualityControl $record): int => self::awaitingValidationCount($record))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('is_mandatory')
                    ->label('Obligatoire')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Oui' : 'Non')
                    ->color(fn (bool $state): string => $state ? 'primary' : 'gray'),
                TextColumn::make('required_by_at')
                    ->label('Exigible depuis')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(QualityControlStatus::options()),
                SelectFilter::make('work_order_id')
                    ->label('OF')
                    ->options(fn (): array => WorkOrder::query()
                        ->whereIn('status', QualityControlResource::visibleWorkOrderStatuses())
                        ->orderBy('number')
                        ->pluck('number', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('work_order_id', $data['value'])
                        : $query),
                SelectFilter::make('work_order_priority')
                    ->label('Priorité OF')
                    ->options(WorkOrderPriority::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('workOrder', fn (Builder $query): Builder => $query->where('priority', $data['value']))
                        : $query),
                Filter::make('to_do')
                    ->label('À effectuer')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        QualityControlStatus::Pending->value,
                        QualityControlStatus::InProgress->value,
                    ])),
                Filter::make('failed')
                    ->label('Échoués')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', QualityControlStatus::Failed->value)),
                Filter::make('late')
                    ->label('En retard')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('required_by_at')
                        ->where('required_by_at', '<', now())
                        ->whereIn('status', [
                            QualityControlStatus::Pending->value,
                            QualityControlStatus::InProgress->value,
                            QualityControlStatus::Failed->value,
                        ])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (WorkOrderQualityControl $record): bool => (bool) auth()->user()?->can('view', $record))
                    ->authorize(fn (WorkOrderQualityControl $record): bool => (bool) auth()->user()?->can('view', $record)),
                QualityControlActions::recordResult(),
                QualityControlActions::secondValidation(),
            ])
            ->toolbarActions([]);
    }

    protected static function awaitingValidationCount(WorkOrderQualityControl $control): int
    {
        return app(QualityControlEvaluationService::class)
            ->progress($control)
            ->filter(fn (QualityControlProgress $progress): bool => $progress->hasPendingValidation)
            ->count();
    }
}
