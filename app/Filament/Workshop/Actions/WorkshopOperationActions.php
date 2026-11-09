<?php

namespace App\Filament\Workshop\Actions;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrderOperation;
use App\Services\OperationTimeEntryService;
use App\Services\OperationTimerService;
use App\Services\WorkOrderOperationService;
use App\Support\DurationFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Actions Filament partagées de l'atelier.
 *
 * Les règles métier et les autorisations restent dans les services et les
 * Policies ; cette classe ne fait que brancher l'interface sur ces couches.
 */
class WorkshopOperationActions
{
    public static function start(): Action
    {
        return self::transitionAction(
            ability: 'start',
            label: 'Démarrer',
            icon: Heroicon::OutlinedPlay,
            color: 'info',
            successTitle: 'Opération démarrée',
            statuses: [WorkshopOperationStatus::Ready],
        );
    }

    public static function pause(): Action
    {
        return self::transitionAction(
            ability: 'pause',
            label: 'Mettre en pause',
            icon: Heroicon::OutlinedPause,
            color: 'warning',
            successTitle: 'Opération mise en pause',
            statuses: [WorkshopOperationStatus::InProgress],
        );
    }

    public static function resume(): Action
    {
        return self::transitionAction(
            ability: 'resume',
            label: 'Reprendre',
            icon: Heroicon::OutlinedArrowPath,
            color: 'info',
            successTitle: 'Opération reprise',
            statuses: [WorkshopOperationStatus::Paused, WorkshopOperationStatus::Blocked],
        );
    }

    public static function unblock(): Action
    {
        return self::transitionAction(
            ability: 'unblock',
            label: 'Débloquer',
            icon: Heroicon::OutlinedLockOpen,
            color: 'success',
            successTitle: 'Opération débloquée',
            statuses: [WorkshopOperationStatus::Blocked],
        );
    }

    public static function complete(): Action
    {
        return self::transitionAction(
            ability: 'complete',
            label: 'Terminer',
            icon: Heroicon::OutlinedCheckCircle,
            color: 'success',
            successTitle: 'Opération terminée',
            statuses: [WorkshopOperationStatus::InProgress],
        );
    }

    public static function block(): Action
    {
        return Action::make('blockOperation')
            ->label('Bloquer')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading('Bloquer l\'opération')
            ->modalDescription('Le motif est obligatoire. Les chronomètres actifs seront arrêtés.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif de blocage')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->visible(fn (WorkOrderOperation $record): bool => in_array($record->status, [
                WorkshopOperationStatus::InProgress,
                WorkshopOperationStatus::Paused,
            ], true) && auth()->user()?->can('block', $record) === true)
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can('block', $record) === true)
            ->action(fn (WorkOrderOperation $record, array $data, Component $livewire) => self::run(
                'block',
                $record,
                'Opération bloquée',
                [(string) ($data['reason'] ?? '')],
                $livewire,
            ));
    }

    public static function cancel(): Action
    {
        return Action::make('cancelOperation')
            ->label('Annuler')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Annuler l\'opération')
            ->modalDescription('Le motif est obligatoire. L\'opération ne sera pas considérée comme terminée.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif d\'annulation')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->visible(fn (WorkOrderOperation $record): bool => in_array($record->status, [
                WorkshopOperationStatus::Ready,
                WorkshopOperationStatus::InProgress,
                WorkshopOperationStatus::Paused,
                WorkshopOperationStatus::Blocked,
            ], true) && auth()->user()?->can('cancel', $record) === true)
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can('cancel', $record) === true)
            ->action(fn (WorkOrderOperation $record, array $data, Component $livewire) => self::run(
                'cancel',
                $record,
                'Opération annulée',
                [(string) ($data['reason'] ?? '')],
                $livewire,
            ));
    }

    public static function startTimer(): Action
    {
        return Action::make('startTimer')
            ->label('Démarrer le chrono')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (WorkOrderOperation $record): bool => $record->status === WorkshopOperationStatus::InProgress
                && auth()->user()?->can('startTimer', $record) === true
                && ! app(OperationTimerService::class)->hasActiveSession(auth()->user()))
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can('startTimer', $record) === true)
            ->action(function (WorkOrderOperation $record, Component $livewire): void {
                self::runTimer('start', $record, 'Chrono démarré', $livewire);
            });
    }

    public static function stopTimer(): Action
    {
        return Action::make('stopTimer')
            ->label('Arrêter le chrono')
            ->icon(Heroicon::OutlinedStop)
            ->color('warning')
            ->visible(fn (WorkOrderOperation $record): bool => auth()->user() !== null
                && app(OperationTimerService::class)->activeEntryForUser($record, auth()->user()) !== null
                && auth()->user()?->can('stopTimer', $record) === true)
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can('stopTimer', $record) === true)
            ->action(function (WorkOrderOperation $record, Component $livewire): void {
                self::runTimer('stop', $record, 'Chrono arrêté', $livewire);
            });
    }

    public static function correctTime(): Action
    {
        return Action::make('correctTime')
            ->label('Corriger un temps')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->modalHeading('Corriger un temps')
            ->modalDescription('L\'entrée d\'origine reste conservée dans l\'historique.')
            ->schema([
                Select::make('time_entry_id')
                    ->label('Entrée à corriger')
                    ->options(fn (WorkOrderOperation $record): array => self::correctableEntryOptions($record))
                    ->searchable()
                    ->required()
                    ->native(false),
                TextInput::make('corrected_duration_seconds')
                    ->label('Durée corrigée (secondes)')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                Textarea::make('correction_reason')
                    ->label('Motif de correction')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->visible(fn (WorkOrderOperation $record): bool => auth()->user()?->can('correctTime', $record) === true
                && self::correctableEntryOptions($record) !== [])
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can('correctTime', $record) === true)
            ->action(function (WorkOrderOperation $record, array $data, Component $livewire): void {
                $entry = $record->timeEntries->firstWhere('id', (int) ($data['time_entry_id'] ?? 0));

                if (! $entry instanceof OperationTimeEntry) {
                    self::fail('L\'entrée de temps sélectionnée est introuvable.');

                    return;
                }

                try {
                    app(OperationTimeEntryService::class)->correct(
                        $entry,
                        (int) $data['corrected_duration_seconds'],
                        (string) $data['correction_reason'],
                        auth()->user(),
                    );

                    self::refresh($livewire);
                    Notification::make()->success()->title('Temps corrigé')->send();
                } catch (ValidationException $exception) {
                    self::refuse($exception);
                }
            });
    }

    public static function recordManualTime(): Action
    {
        return Action::make('recordManualTime')
            ->label('Saisir un temps manuel')
            ->icon(Heroicon::OutlinedClock)
            ->color('primary')
            ->modalHeading('Saisir un temps manuel')
            ->modalDescription('Réservé aux responsables atelier et aux administrateurs.')
            ->schema([
                Select::make('user_id')
                    ->label('Opérateur')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->native(false),
                TextInput::make('duration_seconds')
                    ->label('Durée (secondes)')
                    ->integer()
                    ->minValue(1)
                    ->required(),
                Textarea::make('notes')
                    ->label('Commentaire')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (WorkOrderOperation $record): bool => auth()->user()?->can('recordManualTime', $record) === true)
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can('recordManualTime', $record) === true)
            ->action(function (WorkOrderOperation $record, array $data, Component $livewire): void {
                $operator = User::query()->find($data['user_id'] ?? null);

                if ($operator === null) {
                    self::fail('L\'opérateur sélectionné est introuvable.');

                    return;
                }

                try {
                    app(OperationTimeEntryService::class)->recordManual(
                        $record,
                        $operator,
                        (int) $data['duration_seconds'],
                        null,
                        isset($data['notes']) ? (string) $data['notes'] : null,
                        auth()->user(),
                    );

                    self::refresh($livewire);
                    Notification::make()->success()->title('Temps manuel enregistré')->send();
                } catch (ValidationException $exception) {
                    self::refuse($exception);
                }
            });
    }

    /**
     * @param  array<int, WorkshopOperationStatus>  $statuses
     */
    protected static function transitionAction(
        string $ability,
        string $label,
        mixed $icon,
        string $color,
        string $successTitle,
        array $statuses,
    ): Action {
        return Action::make($ability.'Operation')
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading($label.' l\'opération')
            ->visible(fn (WorkOrderOperation $record): bool => in_array($record->status, $statuses, true)
                && auth()->user()?->can($ability, $record) === true)
            ->authorize(fn (WorkOrderOperation $record): bool => auth()->user()?->can($ability, $record) === true)
            ->action(fn (WorkOrderOperation $record, Component $livewire) => self::run(
                $ability,
                $record,
                $successTitle,
                [],
                $livewire,
            ));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected static function run(
        string $ability,
        WorkOrderOperation $record,
        string $successTitle,
        array $arguments,
        Component $livewire,
    ): void {
        try {
            $operation = app(WorkOrderOperationService::class)
                ->{$ability}($record, auth()->user(), ...$arguments);

            self::refresh($livewire);

            Notification::make()
                ->success()
                ->title($successTitle)
                ->body("Statut actuel : {$operation->status->getLabel()}.")
                ->send();
        } catch (ValidationException $exception) {
            self::refuse($exception);
        }
    }

    protected static function runTimer(
        string $method,
        WorkOrderOperation $record,
        string $successTitle,
        Component $livewire,
    ): void {
        try {
            $entry = app(OperationTimerService::class)->{$method}($record, auth()->user());

            self::refresh($livewire);

            Notification::make()
                ->success()
                ->title($successTitle)
                ->body($method === 'stop'
                    ? 'Durée enregistrée : '.DurationFormatter::format((int) $entry->duration_seconds, true).'.'
                    : 'Session active pour '.auth()->user()->name.'.')
                ->send();
        } catch (ValidationException $exception) {
            self::refuse($exception);
        }
    }

    /**
     * @return array<int, string>
     */
    protected static function correctableEntryOptions(WorkOrderOperation $record): array
    {
        return $record->timeEntries
            ->filter(fn (OperationTimeEntry $entry): bool => in_array($entry->entry_type, [
                OperationTimeEntryType::Timer,
                OperationTimeEntryType::Manual,
            ], true) && $entry->ended_at !== null)
            ->mapWithKeys(fn (OperationTimeEntry $entry): array => [
                $entry->getKey() => sprintf(
                    '%s — %s — %s',
                    $entry->entry_type->getLabel(),
                    $entry->user?->name ?? 'Opérateur inconnu',
                    DurationFormatter::format((int) $entry->duration_seconds),
                ),
            ])
            ->all();
    }

    protected static function refresh(Component $livewire): void
    {
        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }

        if (property_exists($livewire, 'record') && $livewire->record !== null) {
            $livewire->record->refresh();
        }

        if (method_exists($livewire, 'getRecord')) {
            $livewire->getRecord()?->refresh();
        }
    }

    protected static function refuse(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }

    protected static function fail(string $message): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body($message)
            ->send();
    }
}
