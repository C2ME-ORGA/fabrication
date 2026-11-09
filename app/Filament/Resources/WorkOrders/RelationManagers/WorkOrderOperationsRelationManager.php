<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Enums\OperationTimeEntryType;
use App\Enums\WorkshopOperationStatus;
use App\Models\OperationTimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperation;
use App\Models\WorkshopRouting;
use App\Services\OperationTimeAggregationService;
use App\Services\OperationTimeEntryService;
use App\Services\OperationTimerService;
use App\Services\WorkOrderOperationService;
use App\Services\WorkOrderRoutingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorkOrderOperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Opérations de fabrication';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'dependencies',
                'lastStatusAudit.user',
                'timeEntries.user',
            ]))
            ->defaultSort('position')
            ->paginated(false)
            ->header(fn (): View => view('filament.work-orders.routing-summary', [
                'workOrder' => $this->workOrder(),
            ]))
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->width('70px')
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->wrap()
                    ->limit(45),
                TextColumn::make('instructions')
                    ->label('Consignes')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('estimated_duration_minutes')
                    ->label('Durée estimée')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' min')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('is_mandatory')
                    ->label('Obligatoire')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Oui' : 'Non')
                    ->color(fn (bool $state): string => $state ? 'primary' : 'gray'),
                TextColumn::make('dependencies_list')
                    ->label('Dépend de')
                    ->state(fn (WorkOrderOperation $record): string => $record->dependencies
                        ->pluck('code')
                        ->implode(', ') ?: '—')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('blocked_reason')
                    ->label('Motif de blocage')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label('Début')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('Europe/Paris')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->label('Fin')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('Europe/Paris')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('lastStatusAudit.user.name')
                    ->label('Dernière action par')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('effective_duration')
                    ->label('Temps réel')
                    ->state(fn (WorkOrderOperation $record): string => $this->formatDuration(
                        app(OperationTimeAggregationService::class)
                            ->realSecondsFromEntries($record->timeEntries),
                    ))
                    ->toggleable(),
                TextColumn::make('active_timers')
                    ->label('Chrono actif')
                    ->state(fn (WorkOrderOperation $record): string => $record->timeEntries
                        ->whereNull('ended_at')
                        ->map(fn (OperationTimeEntry $entry): ?string => $entry->user?->name)
                        ->filter()
                        ->implode(', ') ?: '—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('assignWorkshopRouting')
                    ->label('Affecter une gamme')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->color('primary')
                    ->modalHeading('Affecter une gamme atelier')
                    ->modalDescription('Seules les gammes actives et valides sont proposées. Les opérations seront copiées dans l\'OF.')
                    ->modalSubmitActionLabel('Affecter et générer')
                    ->schema($this->routingSelectSchema())
                    ->visible(fn (): bool => $this->canAssignRouting())
                    ->authorize(fn (): bool => $this->canAssignRouting())
                    ->action(function (array $data): void {
                        $this->changeRouting($data, replace: false);
                    }),
                Action::make('replaceWorkshopRouting')
                    ->label('Remplacer la gamme')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->modalHeading('Remplacer la gamme atelier')
                    ->modalDescription('Les opérations actuelles seront supprimées uniquement si elles n\'ont aucun historique d\'exécution ni session de temps.')
                    ->modalSubmitActionLabel('Remplacer et régénérer')
                    ->schema($this->routingSelectSchema(excludeCurrent: true))
                    ->visible(fn (): bool => $this->canReplaceRouting())
                    ->authorize(fn (): bool => $this->canReplaceRouting())
                    ->action(function (array $data): void {
                        $this->changeRouting($data, replace: true);
                    }),
                Action::make('recordManualTime')
                    ->label('Saisir un temps manuel')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('primary')
                    ->modalHeading('Saisir un temps manuel')
                    ->modalDescription('Réservé aux responsables atelier et aux administrateurs.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->schema([
                        Select::make('operation_id')
                            ->label('Opération')
                            ->options(fn (): array => $this->operationOptions())
                            ->searchable()
                            ->required()
                            ->native(false),
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
                    ->visible(fn (): bool => $this->canRecordManualTime())
                    ->authorize(fn (): bool => $this->canRecordManualTime())
                    ->action(function (array $data): void {
                        $this->recordManualTime($data);
                    }),
            ])
            ->recordActions([
                ...$this->timeActions(),
                ...$this->executionActions(),
                EditAction::make()
                    ->schema($this->operationFormSchema())
                    ->visible(fn (WorkOrderOperation $record): bool => $this->canOnOperation('update', $record))
                    ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('update', $record))
                    ->successNotification(null)
                    ->using(function (WorkOrderOperation $record, array $data): void {
                        $this->assertCanOnOperation('update', $record);

                        try {
                            $operation = app(WorkOrderRoutingService::class)->updateOperation(
                                $record,
                                $data,
                                $this->actor(),
                            );

                            Notification::make()
                                ->success()
                                ->title('Opération modifiée')
                                ->body("L'opération {$operation->code} a été mise à jour.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('manageOperationDependencies')
                    ->label('Dépendances')
                    ->icon(Heroicon::OutlinedShare)
                    ->color('info')
                    ->modalHeading(fn (WorkOrderOperation $record): string => "Dépendances de {$record->code}")
                    ->modalDescription('Les dépendances restent limitées aux opérations de cet OF. Les cycles sont refusés.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->schema([
                        CheckboxList::make('dependencies')
                            ->label('Opérations préalables')
                            ->options(fn (WorkOrderOperation $record): array => $this->dependencyOptions($record))
                            ->columns(2),
                    ])
                    ->fillForm(fn (WorkOrderOperation $record): array => [
                        'dependencies' => $record->dependencies()
                            ->pluck('work_order_operations.id')
                            ->all(),
                    ])
                    ->visible(fn (WorkOrderOperation $record): bool => $this->canOnOperation('manageDependencies', $record))
                    ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('manageDependencies', $record))
                    ->action(function (WorkOrderOperation $record, array $data): void {
                        $this->assertCanOnOperation('manageDependencies', $record);

                        try {
                            app(WorkOrderRoutingService::class)->syncOperationDependencies(
                                $record,
                                $data['dependencies'] ?? [],
                                $this->actor(),
                            );

                            Notification::make()
                                ->success()
                                ->title('Dépendances enregistrées')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                DeleteAction::make()
                    ->visible(fn (WorkOrderOperation $record): bool => $this->canOnOperation('delete', $record))
                    ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('delete', $record))
                    ->successNotification(null)
                    ->failureNotification(null)
                    ->using(function (WorkOrderOperation $record): bool {
                        $this->assertCanOnOperation('delete', $record);

                        try {
                            app(WorkOrderRoutingService::class)->deleteOperation($record, $this->actor());

                            Notification::make()
                                ->success()
                                ->title('Opération supprimée')
                                ->body("L'opération {$record->code} a été supprimée.")
                                ->send();

                            return true;
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);

                            return false;
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, Action>
     */
    protected function timeActions(): array
    {
        return [
            Action::make('startTimer')
                ->label('Démarrer le chrono')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->visible(fn (WorkOrderOperation $record): bool => $record->status === WorkshopOperationStatus::InProgress
                    && $this->canOnOperation('startTimer', $record)
                    && ! $this->currentUserHasActiveSession())
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('startTimer', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTimerAction('start', $record, 'Chrono démarré')),
            Action::make('stopTimer')
                ->label('Arrêter le chrono')
                ->icon(Heroicon::OutlinedStop)
                ->color('warning')
                ->visible(fn (WorkOrderOperation $record): bool => $this->currentUserActiveEntry($record) !== null
                    && $this->canOnOperation('stopTimer', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('stopTimer', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTimerAction('stop', $record, 'Chrono arrêté')),
            Action::make('correctTime')
                ->label('Corriger un temps')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->modalHeading('Corriger un temps')
                ->modalDescription('La correction est ajoutée à l\'historique : l\'entrée d\'origine reste consultable.')
                ->modalSubmitActionLabel('Enregistrer la correction')
                ->schema([
                    Select::make('time_entry_id')
                        ->label('Entrée à corriger')
                        ->options(fn (WorkOrderOperation $record): array => $this->correctableEntryOptions($record))
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
                ->visible(fn (WorkOrderOperation $record): bool => $this->canCorrectTime($record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('correctTime', $record))
                ->action(function (WorkOrderOperation $record, array $data): void {
                    $this->correctTime($record, $data);
                }),
        ];
    }

    protected function runTimerAction(string $method, WorkOrderOperation $record, string $successTitle): void
    {
        try {
            $entry = app(OperationTimerService::class)->{$method}($record, $this->actor());

            $this->resetTable();

            Notification::make()
                ->success()
                ->title($successTitle)
                ->body($method === 'stop'
                    ? 'Durée enregistrée : '.$this->formatDuration((int) $entry->duration_seconds).'.'
                    : 'La session est active pour '.$this->actor()->name.'.')
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function recordManualTime(array $data): void
    {
        $operation = $this->workOrder()->operations()
            ->whereKey($data['operation_id'] ?? null)
            ->first();

        $operator = User::query()->find($data['user_id'] ?? null);

        if ($operation === null || $operator === null) {
            Notification::make()
                ->danger()
                ->title('Saisie refusée')
                ->body('L\'opération ou l\'opérateur sélectionné est introuvable.')
                ->send();

            return;
        }

        try {
            app(OperationTimeEntryService::class)->recordManual(
                $operation,
                $operator,
                (int) $data['duration_seconds'],
                null,
                isset($data['notes']) ? (string) $data['notes'] : null,
                $this->actor(),
            );

            $this->resetTable();

            Notification::make()
                ->success()
                ->title('Temps manuel enregistré')
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function correctTime(WorkOrderOperation $record, array $data): void
    {
        $entry = $record->timeEntries->firstWhere('id', (int) ($data['time_entry_id'] ?? 0));

        if (! $entry instanceof OperationTimeEntry) {
            Notification::make()
                ->danger()
                ->title('Correction refusée')
                ->body('L\'entrée de temps sélectionnée est introuvable.')
                ->send();

            return;
        }

        try {
            app(OperationTimeEntryService::class)->correct(
                $entry,
                (int) $data['corrected_duration_seconds'],
                (string) $data['correction_reason'],
                $this->actor(),
            );

            $this->resetTable();

            Notification::make()
                ->success()
                ->title('Temps corrigé')
                ->body('L\'entrée d\'origine reste conservée dans l\'historique.')
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function operationOptions(): array
    {
        return $this->workOrder()
            ->operations()
            ->where('status', '!=', WorkshopOperationStatus::Cancelled->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (WorkOrderOperation $operation): array => [
                $operation->getKey() => "{$operation->code} — {$operation->name}",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function correctableEntryOptions(WorkOrderOperation $record): array
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
                    $this->formatDuration((int) $entry->duration_seconds),
                ),
            ])
            ->all();
    }

    protected function canRecordManualTime(): bool
    {
        return auth()->user()?->canManageOperationTimes() === true
            && $this->workOrder()->allowsOperationExecution();
    }

    protected function canCorrectTime(WorkOrderOperation $record): bool
    {
        return $this->canOnOperation('correctTime', $record)
            && $this->correctableEntryOptions($record) !== [];
    }

    protected function currentUserActiveEntry(WorkOrderOperation $record): ?OperationTimeEntry
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return $record->timeEntries
            ->first(fn (OperationTimeEntry $entry): bool => (int) $entry->user_id === (int) $user->getKey()
                && $entry->ended_at === null);
    }

    protected function currentUserHasActiveSession(): bool
    {
        $user = auth()->user();

        return $user !== null && app(OperationTimerService::class)->hasActiveSession($user);
    }

    protected function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours} h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes} min";
        }

        if ($remaining > 0 || $parts === []) {
            $parts[] = "{$remaining} s";
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, Action>
     */
    protected function executionActions(): array
    {
        return [
            Action::make('startOperation')
                ->label('Démarrer')
                ->icon(Heroicon::OutlinedPlay)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Démarrer l\'opération')
                ->visible(fn (WorkOrderOperation $record): bool => $record->status === WorkshopOperationStatus::Ready
                    && $this->canOnOperation('start', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('start', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTransition('start', $record, 'Opération démarrée')),
            Action::make('pauseOperation')
                ->label('Mettre en pause')
                ->icon(Heroicon::OutlinedPause)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Mettre l\'opération en pause')
                ->visible(fn (WorkOrderOperation $record): bool => $record->status === WorkshopOperationStatus::InProgress
                    && $this->canOnOperation('pause', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('pause', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTransition('pause', $record, 'Opération mise en pause')),
            Action::make('resumeOperation')
                ->label('Reprendre')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Reprendre l\'opération')
                ->visible(fn (WorkOrderOperation $record): bool => in_array($record->status, [WorkshopOperationStatus::Paused, WorkshopOperationStatus::Blocked], true)
                    && $this->canOnOperation('resume', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('resume', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTransition('resume', $record, 'Opération reprise')),
            Action::make('blockOperation')
                ->label('Bloquer')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->modalHeading('Bloquer l\'opération')
                ->modalDescription('Le motif est obligatoire et restera conservé dans l\'historique.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Motif de blocage')
                        ->required()
                        ->maxLength(2000)
                        ->rows(3),
                ])
                ->visible(fn (WorkOrderOperation $record): bool => in_array($record->status, [WorkshopOperationStatus::InProgress, WorkshopOperationStatus::Paused], true)
                    && $this->canOnOperation('block', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('block', $record))
                ->action(fn (WorkOrderOperation $record, array $data) => $this->runTransition(
                    'block',
                    $record,
                    'Opération bloquée',
                    [(string) ($data['reason'] ?? '')],
                )),
            Action::make('unblockOperation')
                ->label('Débloquer')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Débloquer l\'opération')
                ->modalDescription('Le statut sera recalculé : prêt si les dépendances sont terminées, en attente sinon.')
                ->visible(fn (WorkOrderOperation $record): bool => $record->status === WorkshopOperationStatus::Blocked
                    && $this->canOnOperation('unblock', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('unblock', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTransition('unblock', $record, 'Opération débloquée')),
            Action::make('completeOperation')
                ->label('Terminer')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Terminer l\'opération')
                ->modalDescription('Les opérations dépendantes seront recalculées automatiquement.')
                ->visible(fn (WorkOrderOperation $record): bool => $record->status === WorkshopOperationStatus::InProgress
                    && $this->canOnOperation('complete', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('complete', $record))
                ->action(fn (WorkOrderOperation $record) => $this->runTransition('complete', $record, 'Opération terminée')),
            Action::make('cancelOperation')
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
                    WorkshopOperationStatus::Paused,
                    WorkshopOperationStatus::Blocked,
                ], true)
                    && $this->canOnOperation('cancel', $record))
                ->authorize(fn (WorkOrderOperation $record): bool => $this->canOnOperation('cancel', $record))
                ->action(fn (WorkOrderOperation $record, array $data) => $this->runTransition(
                    'cancel',
                    $record,
                    'Opération annulée',
                    [(string) ($data['reason'] ?? '')],
                )),
        ];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function runTransition(
        string $ability,
        WorkOrderOperation $record,
        string $successTitle,
        array $arguments = [],
    ): void {
        $this->assertCanOnOperation($ability, $record);

        try {
            $operation = app(WorkOrderOperationService::class)
                ->{$ability}($record, $this->actor(), ...$arguments);

            $this->resetTable();

            Notification::make()
                ->success()
                ->title($successTitle)
                ->body("Statut actuel : {$operation->status->getLabel()}.")
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function routingSelectSchema(bool $excludeCurrent = false): array
    {
        return [
            Section::make('Gamme atelier')
                ->schema([
                    Select::make('workshop_routing_id')
                        ->label('Gamme')
                        ->options(fn (): array => $this->routingOptions($excludeCurrent))
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->helperText('Code, nom, nombre d\'opérations et durée estimée sont affichés dans la liste.'),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function operationFormSchema(): array
    {
        return [
            Section::make('Opération')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('name')
                        ->label('Nom')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('estimated_duration_minutes')
                        ->label('Durée estimée')
                        ->integer()
                        ->minValue(0)
                        ->suffix('min'),
                    Toggle::make('is_mandatory')
                        ->label('Opération obligatoire'),
                    Textarea::make('instructions')
                        ->label('Consignes')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function changeRouting(array $data, bool $replace): void
    {
        $workOrder = $this->workOrder();
        $this->assertCan('manageRouting', $workOrder);

        $routing = WorkshopRouting::query()->find($data['workshop_routing_id'] ?? null);

        if ($routing === null) {
            Notification::make()
                ->danger()
                ->title('Gamme introuvable')
                ->body('La gamme sélectionnée n\'existe pas.')
                ->send();

            return;
        }

        try {
            $service = app(WorkOrderRoutingService::class);

            if ($replace) {
                $service->replace($workOrder, $routing, $this->actor());
            } else {
                $service->assign($workOrder, $routing, $this->actor());
            }

            $this->getOwnerRecord()->refresh();
            $this->resetTable();

            Notification::make()
                ->success()
                ->title($replace ? 'Gamme remplacée' : 'Gamme affectée')
                ->body("Les opérations de la gamme {$routing->code} ont été générées dans l'OF.")
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function routingOptions(bool $excludeCurrent): array
    {
        $query = WorkshopRouting::query()
            ->where('is_active', true)
            ->withCount('operations')
            ->withSum('operations', 'estimated_duration_minutes')
            ->orderBy('code');

        if ($excludeCurrent && $this->workOrder()->workshop_routing_id !== null) {
            $query->whereKeyNot($this->workOrder()->workshop_routing_id);
        }

        return $query->get()
            ->mapWithKeys(fn (WorkshopRouting $routing): array => [
                $routing->getKey() => sprintf(
                    '%s — %s (%d op., %d min)',
                    $routing->code,
                    $routing->name,
                    (int) $routing->operations_count,
                    (int) ($routing->operations_sum_estimated_duration_minutes ?? 0),
                ),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function dependencyOptions(WorkOrderOperation $operation): array
    {
        return $this->workOrder()
            ->operations()
            ->whereKeyNot($operation->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (WorkOrderOperation $candidate): array => [
                $candidate->getKey() => "{$candidate->code} — {$candidate->name}",
            ])
            ->all();
    }

    protected function canAssignRouting(): bool
    {
        $workOrder = $this->workOrder();

        return $workOrder->workshop_routing_id === null
            && (bool) auth()->user()?->can('manageRouting', $workOrder);
    }

    protected function canReplaceRouting(): bool
    {
        $workOrder = $this->workOrder();

        return $workOrder->workshop_routing_id !== null
            && (bool) auth()->user()?->can('manageRouting', $workOrder);
    }

    protected function canOnOperation(string $ability, WorkOrderOperation $operation): bool
    {
        return (bool) auth()->user()?->can($ability, $operation);
    }

    protected function assertCanOnOperation(string $ability, WorkOrderOperation $operation): void
    {
        $user = $this->actor();

        Gate::forUser($user)->authorize($ability, $operation);
    }

    protected function assertCan(string $ability, mixed $arguments): void
    {
        Gate::forUser($this->actor())->authorize($ability, $arguments);
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        return $user;
    }

    protected function workOrder(): WorkOrder
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $this->getOwnerRecord();

        return $workOrder;
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
