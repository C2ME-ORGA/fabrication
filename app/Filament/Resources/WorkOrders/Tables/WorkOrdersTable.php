<?php

namespace App\Filament\Resources\WorkOrders\Tables;

use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class WorkOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                TextColumn::make('job.code')
                    ->label('Affaire')
                    ->searchable()
                    ->sortable()
                    ->url(fn (WorkOrder $record): string => JobResource::getUrl('view', ['record' => $record->job_id])),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Priorité')
                    ->badge()
                    ->sortable(),
                TextColumn::make('planned_start_at')
                    ->label('Début prévu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('planned_end_at')
                    ->label('Fin prévue')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('overdue')
                    ->label('Retard')
                    ->badge()
                    ->color('danger')
                    ->state(fn (WorkOrder $record): ?string => $record->planned_end_at?->isPast()
                        && ! $record->isLocked()
                            ? 'En retard'
                            : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(WorkOrderStatus::options()),
                SelectFilter::make('priority')
                    ->label('Priorité')
                    ->options(WorkOrderPriority::options()),
                SelectFilter::make('job_id')
                    ->label('Affaire')
                    ->relationship('job', 'code')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ...self::transitionActions(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (WorkOrder $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->action(function (WorkOrder $record): void {
                        try {
                            app(WorkOrderService::class)->delete($record);

                            Notification::make()
                                ->success()
                                ->title("OF {$record->number} supprimé")
                                ->send();
                        } catch (ValidationException $exception) {
                            self::notifyRefusal($exception);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * One explicit action per status, shown only when the transition is
     * allowed by the domain matrix.
     *
     * @return array<int, Action>
     */
    protected static function transitionActions(): array
    {
        $actions = [];

        foreach (WorkOrderStatus::cases() as $status) {
            if ($status === WorkOrderStatus::Draft) {
                continue;
            }

            $actions[] = Action::make('transition_'.$status->value)
                ->label($status->getLabel())
                ->icon(Heroicon::OutlinedArrowRight)
                ->color($status->getColor())
                ->requiresConfirmation()
                ->modalHeading("Passer l'OF au statut « {$status->getLabel()} »")
                ->visible(fn (WorkOrder $record): bool => $record->canTransitionTo($status)
                    && (bool) auth()->user()?->can('transition', $record))
                ->action(fn (WorkOrder $record) => self::transition($record, $status));
        }

        return $actions;
    }

    protected static function transition(WorkOrder $record, WorkOrderStatus $status): void
    {
        try {
            app(WorkOrderService::class)->transitionTo($record, $status, auth()->user());

            Notification::make()
                ->success()
                ->title("OF {$record->number} : {$status->getLabel()}")
                ->send();
        } catch (ValidationException $exception) {
            self::notifyRefusal($exception);
        }
    }

    protected static function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
