<?php

namespace App\Filament\Resources\WorkshopRoutings\RelationManagers;

use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Services\OperationDependencyService;
use App\Services\WorkshopRoutingOperationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Opérations';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn ($query) => $query->with('dependencies'))
            ->defaultSort('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->sortable()
                    ->width('70px'),
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
                    ->label('Opérations préalables')
                    ->state(fn (WorkshopRoutingOperation $record): string => $record->dependencies
                        ->pluck('code')
                        ->implode(', ') ?: '—')
                    ->wrap(),
                TextColumn::make('instructions')
                    ->label('Consignes')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('createOperation')
                    ->label('Ajouter une opération')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->modalHeading('Ajouter une opération')
                    ->modalSubmitActionLabel('Ajouter')
                    ->schema($this->operationFormSchema())
                    ->visible(fn (): bool => $this->canManageOperations())
                    ->authorize(fn (): bool => $this->canManageOperations())
                    ->action(function (array $data): void {
                        $this->assertCanManageOperations();

                        try {
                            $operation = app(WorkshopRoutingOperationService::class)
                                ->create($this->routing(), $data);

                            Notification::make()
                                ->success()
                                ->title('Opération ajoutée')
                                ->body("L'opération {$operation->code} a été ajoutée.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema($this->operationFormSchema())
                    ->visible(fn (): bool => $this->canManageOperations())
                    ->authorize(fn (): bool => $this->canManageOperations())
                    ->successNotification(null)
                    ->using(function (WorkshopRoutingOperation $record, array $data): void {
                        $this->assertCanManageOperations();

                        try {
                            $operation = app(WorkshopRoutingOperationService::class)->update($record, $data);

                            Notification::make()
                                ->success()
                                ->title('Opération modifiée')
                                ->body("L'opération {$operation->code} a été mise à jour.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('manageDependencies')
                    ->label('Dépendances')
                    ->icon(Heroicon::OutlinedShare)
                    ->color('info')
                    ->modalHeading(fn (WorkshopRoutingOperation $record): string => "Dépendances de {$record->code}")
                    ->modalDescription('Sélectionnez les opérations qui doivent être terminées avant celle-ci. Les cycles sont refusés automatiquement.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->schema([
                        CheckboxList::make('dependencies')
                            ->label('Opérations préalables')
                            ->options(fn (WorkshopRoutingOperation $record): array => $this->dependencyOptions($record))
                            ->columns(2)
                            ->helperText('Laisser vide si l\'opération peut démarrer immédiatement.'),
                    ])
                    ->fillForm(fn (WorkshopRoutingOperation $record): array => [
                        'dependencies' => $record->dependencies()
                            ->pluck('workshop_routing_operations.id')
                            ->all(),
                    ])
                    ->visible(fn (): bool => $this->canManageDependencies())
                    ->authorize(fn (): bool => $this->canManageDependencies())
                    ->action(function (WorkshopRoutingOperation $record, array $data): void {
                        $this->assertCanManageDependencies();

                        try {
                            app(OperationDependencyService::class)->syncDependencies(
                                $this->routing(),
                                $record,
                                $data['dependencies'] ?? [],
                            );

                            Notification::make()
                                ->success()
                                ->title('Dépendances enregistrées')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('moveUp')
                    ->label('Monter')
                    ->icon(Heroicon::OutlinedArrowUp)
                    ->visible(fn (): bool => $this->canManageOperations())
                    ->authorize(fn (): bool => $this->canManageOperations())
                    ->action(function (WorkshopRoutingOperation $record): void {
                        $this->move($record, 'moveUp');
                    }),
                Action::make('moveDown')
                    ->label('Descendre')
                    ->icon(Heroicon::OutlinedArrowDown)
                    ->visible(fn (): bool => $this->canManageOperations())
                    ->authorize(fn (): bool => $this->canManageOperations())
                    ->action(function (WorkshopRoutingOperation $record): void {
                        $this->move($record, 'moveDown');
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canManageOperations())
                    ->authorize(fn (): bool => $this->canManageOperations())
                    ->successNotification(null)
                    ->failureNotification(null)
                    ->using(function (WorkshopRoutingOperation $record): bool {
                        $this->assertCanManageOperations();

                        try {
                            app(WorkshopRoutingOperationService::class)->delete($record);

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
                        ->suffix('min')
                        ->helperText('Valeur positive ou nulle.'),
                    Toggle::make('is_mandatory')
                        ->label('Opération obligatoire')
                        ->default(true),
                    Textarea::make('instructions')
                        ->label('Consignes')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function dependencyOptions(WorkshopRoutingOperation $operation): array
    {
        return $this->routing()
            ->operations()
            ->whereKeyNot($operation->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (WorkshopRoutingOperation $candidate): array => [
                $candidate->getKey() => "{$candidate->code} — {$candidate->name}",
            ])
            ->all();
    }

    protected function move(WorkshopRoutingOperation $record, string $operation): void
    {
        $this->assertCanManageOperations();

        try {
            app(WorkshopRoutingOperationService::class)->{$operation}($record);
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    protected function routing(): WorkshopRouting
    {
        /** @var WorkshopRouting $routing */
        $routing = $this->getOwnerRecord();

        return $routing;
    }

    protected function canManageOperations(): bool
    {
        return (bool) auth()->user()?->can('manageOperations', $this->routing());
    }

    protected function canManageDependencies(): bool
    {
        return (bool) auth()->user()?->can('manageDependencies', $this->routing());
    }

    protected function assertCanManageOperations(): void
    {
        $this->assertCan('manageOperations');
    }

    protected function assertCanManageDependencies(): void
    {
        $this->assertCan('manageDependencies');
    }

    protected function assertCan(string $ability): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        Gate::forUser($user)->authorize($ability, $this->routing());
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
