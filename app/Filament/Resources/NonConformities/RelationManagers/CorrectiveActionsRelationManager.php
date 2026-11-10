<?php

namespace App\Filament\Resources\NonConformities\RelationManagers;

use App\Enums\CorrectiveActionStatus;
use App\Models\NonConformity;
use App\Models\NonConformityCorrectiveAction;
use App\Models\User;
use App\Services\CorrectiveActionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CorrectiveActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'correctiveActions';

    protected static ?string $title = 'Actions correctives';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->width('70px'),
                TextColumn::make('description')
                    ->label('Action')
                    ->wrap()
                    ->limit(80),
                TextColumn::make('responsible.name')
                    ->label('Responsable')
                    ->placeholder('—'),
                TextColumn::make('due_date')
                    ->label('Échéance')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('completed_at')
                    ->label('Terminée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('evidence_comment')
                    ->label('Preuve')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('addCorrectiveAction')
                    ->label('Ajouter une action')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->modalHeading('Ajouter une action corrective')
                    ->schema([
                        Textarea::make('description')
                            ->label('Description')
                            ->required()
                            ->rows(3),
                        Select::make('responsible_id')
                            ->label('Responsable')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->native(false),
                        DatePicker::make('due_date')->label('Échéance'),
                        Toggle::make('verification_required')
                            ->label('Vérification requise')
                            ->default(true),
                    ])
                    ->visible(fn (): bool => $this->canManage())
                    ->authorize(fn (): bool => $this->canManage())
                    ->action(function (array $data): void {
                        try {
                            app(CorrectiveActionService::class)->add(
                                $this->nonConformity(),
                                $data,
                                $this->actor(),
                            );

                            $this->resetTable();
                            Notification::make()->success()->title('Action corrective ajoutée')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('startCorrectiveAction')
                    ->label('Démarrer')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (NonConformityCorrectiveAction $record): bool => $record->status === CorrectiveActionStatus::Planned
                        && $this->canOnAction('update', $record))
                    ->authorize(fn (NonConformityCorrectiveAction $record): bool => $this->canOnAction('update', $record))
                    ->action(function (NonConformityCorrectiveAction $record): void {
                        $this->runAction(fn () => app(CorrectiveActionService::class)->start($record, $this->actor()), 'Action démarrée');
                    }),
                Action::make('completeCorrectiveAction')
                    ->label('Terminer')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->modalHeading('Terminer l\'action corrective')
                    ->schema([
                        Textarea::make('evidence_comment')
                            ->label('Preuve de réalisation')
                            ->required()
                            ->rows(3),
                    ])
                    ->visible(fn (NonConformityCorrectiveAction $record): bool => in_array($record->status, [
                        CorrectiveActionStatus::Planned,
                        CorrectiveActionStatus::InProgress,
                    ], true) && $this->canOnAction('complete', $record))
                    ->authorize(fn (NonConformityCorrectiveAction $record): bool => $this->canOnAction('complete', $record))
                    ->action(function (NonConformityCorrectiveAction $record, array $data): void {
                        $this->runAction(
                            fn () => app(CorrectiveActionService::class)->complete(
                                $record,
                                $this->actor(),
                                (string) ($data['evidence_comment'] ?? ''),
                            ),
                            'Action terminée',
                        );
                    }),
                Action::make('cancelCorrectiveAction')
                    ->label('Annuler')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->modalHeading('Annuler l\'action corrective')
                    ->schema([
                        Textarea::make('reason')->label('Motif')->required()->rows(3),
                    ])
                    ->visible(fn (NonConformityCorrectiveAction $record): bool => $record->status->isOpen()
                        && $this->canOnAction('update', $record))
                    ->authorize(fn (NonConformityCorrectiveAction $record): bool => $this->canOnAction('update', $record))
                    ->action(function (NonConformityCorrectiveAction $record, array $data): void {
                        $this->runAction(
                            fn () => app(CorrectiveActionService::class)->cancel(
                                $record,
                                $this->actor(),
                                (string) ($data['reason'] ?? ''),
                            ),
                            'Action annulée',
                        );
                    }),
                Action::make('markCorrectiveActionIneffective')
                    ->label('Inefficace')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->modalHeading('Déclarer l\'action inefficace')
                    ->schema([
                        Textarea::make('reason')->label('Motif')->required()->rows(3),
                    ])
                    ->visible(fn (NonConformityCorrectiveAction $record): bool => in_array($record->status, [
                        CorrectiveActionStatus::InProgress,
                        CorrectiveActionStatus::Done,
                    ], true) && $this->canOnAction('update', $record))
                    ->authorize(fn (NonConformityCorrectiveAction $record): bool => $this->canOnAction('update', $record))
                    ->action(function (NonConformityCorrectiveAction $record, array $data): void {
                        $this->runAction(
                            fn () => app(CorrectiveActionService::class)->markIneffective(
                                $record,
                                $this->actor(),
                                (string) ($data['reason'] ?? ''),
                            ),
                            'Action déclarée inefficace',
                        );
                    }),
            ])
            ->toolbarActions([]);
    }

    protected function runAction(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            $this->resetTable();
            Notification::make()->success()->title($successTitle)->send();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
        }
    }

    protected function canManage(): bool
    {
        return (bool) auth()->user()?->can('manageCorrectiveActions', $this->nonConformity());
    }

    protected function canOnAction(string $ability, NonConformityCorrectiveAction $action): bool
    {
        return (bool) auth()->user()?->can($ability, $action);
    }

    protected function nonConformity(): NonConformity
    {
        /** @var NonConformity $nonConformity */
        $nonConformity = $this->getOwnerRecord();

        return $nonConformity;
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        return $user;
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
