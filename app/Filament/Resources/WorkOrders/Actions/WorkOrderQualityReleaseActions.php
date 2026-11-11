<?php

namespace App\Filament\Resources\WorkOrders\Actions;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Services\WorkOrderQualityReleaseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class WorkOrderQualityReleaseActions
{
    public static function historicalRelease(): Action
    {
        return Action::make('recordHistoricalQualityRelease')
            ->label('Libérer la qualité')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->requiresConfirmation()
            ->modalHeading('Enregistrer une libération qualité historique')
            ->modalDescription('Cette action crée une preuve persistée à partir des règles FC-7, hors du court-circuit des OF terminés. Aucune preuve ne sera reconstruite depuis l’audit.')
            ->schema([
                Textarea::make('comment')
                    ->label('Motif de la revue historique')
                    ->required()
                    ->minLength(5)
                    ->rows(3),
            ])
            ->action(function (WorkOrder $record, array $data): void {
                try {
                    $release = app(WorkOrderQualityReleaseService::class)
                        ->recordHistoricalReview($record, auth()->user(), (string) $data['comment']);

                    Notification::make()
                        ->success()
                        ->title('Libération qualité enregistrée')
                        ->body('Preuve '.$release->reference().' archivée.')
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Libération qualité refusée')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->send();
                }
            })
            ->authorize(fn (WorkOrder $record): bool => (bool) auth()->user()?->can('releaseQuality', $record))
            ->visible(fn (WorkOrder $record): bool => $record->status === WorkOrderStatus::Completed
                && $record->latestQualityRelease()?->eligible !== true
                && (bool) auth()->user()?->can('releaseQuality', $record));
    }
}
