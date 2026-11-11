<?php

namespace App\Filament\Resources\WorkOrders\Actions;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use App\Services\WorkOrderPdfGenerationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class WorkOrderPrintActions
{
    public static function generatePdf(): Action
    {
        return Action::make('generatePdf')
            ->label('Générer le PDF')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->requiresConfirmation()
            ->modalHeading('Générer le PDF de l\'OF')
            ->modalDescription('Le PDF sera généré depuis les données enregistrées, archivé et conservé. Une génération précédente ne sera jamais écrasée.')
            ->visible(fn (WorkOrder $record): bool => auth()->user()?->can('generatePdf', $record) === true)
            ->authorize(fn (WorkOrder $record): bool => auth()->user()?->can('generatePdf', $record) === true)
            ->action(function (WorkOrder $record): void {
                try {
                    $document = app(WorkOrderPdfGenerationService::class)
                        ->generate($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('PDF généré et archivé')
                        ->body($document->reference)
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Génération refusée')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->send();
                }
            })
            ->successRedirectUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record]));
    }
}
