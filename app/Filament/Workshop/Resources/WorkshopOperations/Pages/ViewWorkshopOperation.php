<?php

namespace App\Filament\Workshop\Resources\WorkshopOperations\Pages;

use App\Filament\Workshop\Actions\WorkshopOperationActions;
use App\Filament\Workshop\Resources\WorkshopOperations\WorkshopOperationResource;
use Filament\Resources\Pages\ViewRecord;

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
}
