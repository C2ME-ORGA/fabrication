<?php

namespace App\Filament\Resources\QualityDeviations\Actions;

use App\Models\QualityDeviation;
use App\Services\QualityDeviationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class QualityDeviationActions
{
    public static function approve(): Action
    {
        return Action::make('approveQualityDeviation')
            ->label('Approuver')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('Approuver la dérogation')
            ->modalDescription(fn (QualityDeviation $record): string => $record->is_critical
                ? 'Une dérogation critique exige deux approbations distinctes.'
                : 'Une approbation active la dérogation.')
            ->schema([
                Textarea::make('comment')
                    ->label('Commentaire de décision')
                    ->rows(3),
            ])
            ->visible(fn (QualityDeviation $record): bool => $record->isPending()
                && (bool) auth()->user()?->can('approve', $record))
            ->authorize(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('approve', $record))
            ->action(function (QualityDeviation $record, array $data): void {
                try {
                    app(QualityDeviationService::class)->approve(
                        $record,
                        auth()->user(),
                        $data['comment'] ?? null,
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Dérogation approuvée')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectQualityDeviation')
            ->label('Refuser')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Refuser la dérogation')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif du refus')
                    ->required()
                    ->rows(3),
            ])
            ->visible(fn (QualityDeviation $record): bool => $record->isPending()
                && (bool) auth()->user()?->can('reject', $record))
            ->authorize(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('reject', $record))
            ->action(function (QualityDeviation $record, array $data): void {
                try {
                    app(QualityDeviationService::class)->reject(
                        $record,
                        auth()->user(),
                        (string) ($data['reason'] ?? ''),
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Dérogation refusée')->send();
                } catch (ValidationException $exception) {
                    self::notifyRefusal($exception);
                }
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revokeQualityDeviation')
            ->label('Révoquer')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading('Révoquer la dérogation')
            ->modalDescription('La révocation est conservée dans l\'historique. L\'exigence initiale redevient bloquante.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif de révocation')
                    ->required()
                    ->minLength(max(20, (int) config('quality.deviations.reason_min_length', 20)))
                    ->rows(3),
            ])
            ->visible(fn (QualityDeviation $record): bool => $record->isActive()
                && (bool) auth()->user()?->can('revoke', $record))
            ->authorize(fn (QualityDeviation $record): bool => (bool) auth()->user()?->can('revoke', $record))
            ->action(function (QualityDeviation $record, array $data): void {
                try {
                    app(QualityDeviationService::class)->revoke(
                        $record,
                        auth()->user(),
                        (string) ($data['reason'] ?? ''),
                    );
                    $record->refresh();

                    Notification::make()->success()->title('Dérogation révoquée')->send();
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
