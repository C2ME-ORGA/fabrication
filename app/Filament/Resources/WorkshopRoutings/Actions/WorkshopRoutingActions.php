<?php

namespace App\Filament\Resources\WorkshopRoutings\Actions;

use App\Models\WorkshopRouting;
use App\Services\WorkshopRoutingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class WorkshopRoutingActions
{
    public static function activate(): Action
    {
        return Action::make('activateRouting')
            ->label('Activer')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Activer la gamme')
            ->modalDescription('La gamme doit contenir des opérations cohérentes et un graphe de dépendances sans cycle.')
            ->visible(fn (WorkshopRouting $record): bool => (bool) auth()->user()?->can('activate', $record))
            ->authorize(fn (WorkshopRouting $record): bool => (bool) auth()->user()?->can('activate', $record))
            ->action(fn (WorkshopRouting $record) => self::changeActivation($record, true));
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivateRouting')
            ->label('Désactiver')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Désactiver la gamme')
            ->modalDescription('Une gamme désactivée reste consultable et conservée historiquement, mais ne sera plus proposée pour de nouveaux OF.')
            ->visible(fn (WorkshopRouting $record): bool => (bool) auth()->user()?->can('deactivate', $record))
            ->authorize(fn (WorkshopRouting $record): bool => (bool) auth()->user()?->can('deactivate', $record))
            ->action(fn (WorkshopRouting $record) => self::changeActivation($record, false));
    }

    protected static function changeActivation(WorkshopRouting $record, bool $activate): void
    {
        try {
            if ($activate) {
                app(WorkshopRoutingService::class)->activate($record);
            } else {
                app(WorkshopRoutingService::class)->deactivate($record);
            }

            Notification::make()
                ->success()
                ->title($activate ? 'Gamme activée' : 'Gamme désactivée')
                ->body("La gamme {$record->code} a été ".($activate ? 'activée' : 'désactivée').'.')
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title($activate ? 'Activation refusée' : 'Désactivation refusée')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        }
    }
}
