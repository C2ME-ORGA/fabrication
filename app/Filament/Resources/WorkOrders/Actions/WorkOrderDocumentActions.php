<?php

namespace App\Filament\Resources\WorkOrders\Actions;

use App\Models\WorkOrder;
use App\Services\DocumentSnapshotService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class WorkOrderDocumentActions
{
    public static function reReviewDocuments(): Action
    {
        return Action::make('reReviewDocuments')
            ->label('Remettre les documents en revue')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->requiresConfirmation()
            ->modalHeading('Remettre les documents en revue')
            ->modalDescription('Cette action fige explicitement les versions approuvées actuellement applicables. Les références de libération précédentes restent conservées dans l’historique.')
            ->schema([
                Textarea::make('comment')
                    ->label('Motif')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (WorkOrder $record, array $data): void {
                try {
                    $snapshots = app(DocumentSnapshotService::class)
                        ->refreshForWorkOrder($record, auth()->user(), (string) $data['comment']);

                    Notification::make()
                        ->success()
                        ->title('Documents remis en revue')
                        ->body("{$snapshots->count()} référence(s) documentaire(s) figée(s).")
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Remise en revue refusée')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->send();
                }
            })
            ->authorize(fn (WorkOrder $record): bool => (bool) auth()->user()?->can('reReviewDocuments', $record))
            ->visible(fn (WorkOrder $record): bool => (bool) auth()->user()?->can('reReviewDocuments', $record));
    }
}
