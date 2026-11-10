<?php

namespace App\Filament\Resources\NonConformities\Actions;

use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Models\NonConformity;
use App\Models\User;
use App\Services\NonConformityLifecycleService;
use App\Services\NonConformityService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class NonConformityActions
{
    public static function qualify(): Action
    {
        return Action::make('qualifyNonConformity')
            ->label('Qualifier')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('primary')
            ->modalHeading('Qualifier la non-conformité')
            ->modalDescription('La gravité peut être confirmée ou requalifiée. Toute modification est auditée et applique la politique correspondante.')
            ->modalSubmitActionLabel('Enregistrer la qualification')
            ->schema([
                Select::make('severity')
                    ->label('Gravité retenue')
                    ->options(NonConformitySeverity::options())
                    ->required()
                    ->native(false),
                Textarea::make('reason')
                    ->label('Motif de qualification')
                    ->required()
                    ->minLength(3)
                    ->rows(3)
                    ->maxLength(5000),
            ])
            ->fillForm(fn (NonConformity $record): array => [
                'severity' => $record->severity->value,
                'reason' => $record->qualification_comment,
            ])
            ->visible(fn (NonConformity $record): bool => (bool) auth()->user()?->can('qualify', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('qualify', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityService::class)->qualify($record, $data, auth()->user());
                    $record->refresh();

                    Notification::make()->success()->title('Non-conformité qualifiée')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function assign(): Action
    {
        return Action::make('assignNonConformity')
            ->label('Affecter / traiter')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('info')
            ->modalHeading('Affecter la non-conformité')
            ->modalSubmitActionLabel('Enregistrer le traitement')
            ->schema([
                Select::make('responsible_id')
                    ->label('Responsable')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false),
                DatePicker::make('due_date')
                    ->label('Échéance'),
                Textarea::make('treatment_plan')
                    ->label('Traitement envisagé')
                    ->rows(3),
                Textarea::make('immediate_action')
                    ->label('Action immédiate / confinement')
                    ->rows(3),
                DateTimePicker::make('containment_at')
                    ->label('Confinement réalisé le')
                    ->maxDate(now()),
            ])
            ->fillForm(fn (NonConformity $record): array => [
                'responsible_id' => $record->responsible_id,
                'due_date' => $record->due_date?->toDateString(),
                'treatment_plan' => $record->treatment_plan,
                'immediate_action' => $record->immediate_action,
                'containment_at' => $record->containment_at,
            ])
            ->visible(fn (NonConformity $record): bool => (bool) auth()->user()?->can('assign', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('assign', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityService::class)->assign($record, $data, auth()->user());
                    $record->refresh();

                    Notification::make()->success()->title('Affectation enregistrée')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function start(): Action
    {
        return Action::make('startNonConformity')
            ->label('Prendre en charge')
            ->icon(Heroicon::OutlinedPlay)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Prendre en charge la non-conformité')
            ->schema([
                Textarea::make('comment')->label('Commentaire')->rows(2),
            ])
            ->visible(fn (NonConformity $record): bool => $record->status === NonConformityStatus::Open
                && (bool) auth()->user()?->can('start', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('start', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityLifecycleService::class)->start(
                        $record,
                        auth()->user(),
                        $data['comment'] ?? null,
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Non-conformité prise en charge')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function submitForVerification(): Action
    {
        return Action::make('submitNonConformityForVerification')
            ->label('Soumettre à vérification')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('warning')
            ->modalHeading('Soumettre la non-conformité à vérification')
            ->modalDescription('Toutes les actions correctives doivent être terminées ou annulées.')
            ->schema([
                Textarea::make('comment')->label('Commentaire')->rows(2),
            ])
            ->visible(fn (NonConformity $record): bool => $record->status === NonConformityStatus::InProgress
                && (bool) auth()->user()?->can('submitForVerification', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('submitForVerification', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityLifecycleService::class)->submitForVerification(
                        $record,
                        auth()->user(),
                        $data['comment'] ?? null,
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Non-conformité soumise à vérification')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function close(): Action
    {
        return Action::make('closeNonConformity')
            ->label('Clôturer')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('Clôturer la non-conformité')
            ->modalDescription('Les vérifications exigées par la politique figée doivent être conformes.')
            ->schema([
                Textarea::make('closure_comment')
                    ->label('Commentaire de clôture')
                    ->required()
                    ->rows(3),
            ])
            ->visible(fn (NonConformity $record): bool => $record->status === NonConformityStatus::PendingVerification
                && (bool) auth()->user()?->can('close', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('close', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityLifecycleService::class)->close(
                        $record,
                        auth()->user(),
                        (string) ($data['closure_comment'] ?? ''),
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Non-conformité clôturée')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopenNonConformity')
            ->label('Réouvrir')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->modalHeading('Réouvrir la non-conformité')
            ->schema([
                Textarea::make('reason')->label('Motif de réouverture')->required()->rows(3),
            ])
            ->visible(fn (NonConformity $record): bool => $record->isClosed()
                && (bool) auth()->user()?->can('reopen', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('reopen', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityLifecycleService::class)->reopen(
                        $record,
                        auth()->user(),
                        (string) ($data['reason'] ?? ''),
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Non-conformité réouverte')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelNonConformity')
            ->label('Annuler')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Annuler la non-conformité')
            ->schema([
                Textarea::make('reason')->label('Motif d\'annulation')->required()->rows(3),
            ])
            ->visible(fn (NonConformity $record): bool => in_array($record->status, [
                NonConformityStatus::Open,
                NonConformityStatus::InProgress,
            ], true) && (bool) auth()->user()?->can('cancel', $record))
            ->authorize(fn (NonConformity $record): bool => (bool) auth()->user()?->can('cancel', $record))
            ->action(function (NonConformity $record, array $data): void {
                try {
                    app(NonConformityLifecycleService::class)->cancel(
                        $record,
                        auth()->user(),
                        (string) ($data['reason'] ?? ''),
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Non-conformité annulée')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
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
