<?php

namespace App\Filament\Resources\WorkshopRoutings\RelationManagers;

use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationDependency;
use App\Services\OperationDependencyService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DependenciesRelationManager extends RelationManager
{
    protected static string $relationship = 'operationDependencies';

    protected static ?string $title = 'Dépendances';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['operation', 'dependsOnOperation'])
                ->orderBy('workshop_routing_operation_dependencies.id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('operation.code')
                    ->label('Opération')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('operation.name')
                    ->label('Nom de l\'opération')
                    ->wrap()
                    ->limit(40),
                TextColumn::make('dependsOnOperation.code')
                    ->label('Dépend de')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('dependsOnOperation.name')
                    ->label('Nom de l\'opération préalable')
                    ->wrap()
                    ->limit(40),
                TextColumn::make('created_at')
                    ->label('Ajoutée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('addDependency')
                    ->label('Ajouter une dépendance')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->modalHeading('Ajouter une dépendance')
                    ->modalDescription('La seconde opération doit être terminée avant que la première puisse démarrer.')
                    ->modalSubmitActionLabel('Ajouter')
                    ->schema([
                        Select::make('workshop_routing_operation_id')
                            ->label('Opération')
                            ->options(fn (): array => $this->operationOptions())
                            ->searchable()
                            ->required()
                            ->native(false),
                        Select::make('depends_on_operation_id')
                            ->label('Doit être terminée avant')
                            ->options(fn (): array => $this->operationOptions())
                            ->searchable()
                            ->required()
                            ->native(false),
                    ])
                    ->visible(fn (): bool => $this->canManageDependencies())
                    ->authorize(fn (): bool => $this->canManageDependencies())
                    ->action(function (array $data): void {
                        $this->assertCanManageDependencies();

                        try {
                            $operation = $this->resolveOperation($data['workshop_routing_operation_id'] ?? null);
                            $dependsOn = $this->resolveOperation($data['depends_on_operation_id'] ?? null);

                            app(OperationDependencyService::class)->add(
                                $this->routing(),
                                $operation,
                                $dependsOn,
                            );

                            Notification::make()
                                ->success()
                                ->title('Dépendance ajoutée')
                                ->body("{$operation->code} dépend désormais de {$dependsOn->code}.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Supprimer')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canManageDependencies())
                    ->authorize(fn (): bool => $this->canManageDependencies())
                    ->successNotification(null)
                    ->failureNotification(null)
                    ->using(function (WorkshopRoutingOperationDependency $record): bool {
                        $this->assertCanManageDependencies();

                        try {
                            app(OperationDependencyService::class)->remove(
                                $this->routing(),
                                $record->operation,
                                $record->dependsOnOperation,
                            );

                            Notification::make()
                                ->success()
                                ->title('Dépendance supprimée')
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
     * @return array<int, string>
     */
    protected function operationOptions(): array
    {
        return $this->routing()
            ->operations()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (WorkshopRoutingOperation $operation): array => [
                $operation->getKey() => "{$operation->code} — {$operation->name}",
            ])
            ->all();
    }

    protected function resolveOperation(mixed $id): WorkshopRoutingOperation
    {
        $operation = $this->routing()->operations()->whereKey($id)->first();

        if ($operation === null) {
            throw ValidationException::withMessages([
                'dependencies' => 'L\'opération sélectionnée n\'appartient pas à cette gamme.',
            ]);
        }

        return $operation;
    }

    protected function routing(): WorkshopRouting
    {
        /** @var WorkshopRouting $routing */
        $routing = $this->getOwnerRecord();

        return $routing;
    }

    protected function canManageDependencies(): bool
    {
        return (bool) auth()->user()?->can('manageDependencies', $this->routing());
    }

    protected function assertCanManageDependencies(): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        Gate::forUser($user)->authorize('manageDependencies', $this->routing());
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
