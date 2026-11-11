<?php

namespace App\Filament\Workshop\Resources\WorkshopOperations\Pages;

use App\Filament\Workshop\Actions\WorkshopOperationActions;
use App\Filament\Workshop\Resources\WorkshopOperations\WorkshopOperationResource;
use App\Models\WorkOrderOperation;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewWorkshopOperation extends ViewRecord
{
    protected static string $resource = WorkshopOperationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->loadMissing(['auditLogs.user']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('workOrderPdf')
                ->label('PDF de l\'OF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (WorkOrderOperation $record): ?string => $this->workOrderPdfUrl($record))
                ->openUrlInNewTab()
                ->visible(fn (WorkOrderOperation $record): bool => $this->workOrderPdfUrl($record) !== null),
            WorkshopOperationActions::start(),
            WorkshopOperationActions::pause(),
            WorkshopOperationActions::resume(),
            WorkshopOperationActions::block(),
            WorkshopOperationActions::unblock(),
            WorkshopOperationActions::complete(),
            WorkshopOperationActions::cancel(),
            WorkshopOperationActions::startTimer(),
            WorkshopOperationActions::stopTimer(),
            WorkshopOperationActions::recordManualTime(),
            WorkshopOperationActions::correctTime(),
        ];
    }

    protected function workOrderPdfUrl(WorkOrderOperation $operation): ?string
    {
        $operation->loadMissing('workOrder');
        $workOrder = $operation->workOrder;

        if ($workOrder === null || auth()->user()?->can('viewPdf', $workOrder) !== true) {
            return null;
        }

        $document = $workOrder->latestGeneratedDocument();

        return $document === null ? null : route('generated-documents.view', $document);
    }
}
