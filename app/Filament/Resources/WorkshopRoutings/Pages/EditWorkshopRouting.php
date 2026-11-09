<?php

namespace App\Filament\Resources\WorkshopRoutings\Pages;

use App\Filament\Resources\WorkshopRoutings\Actions\WorkshopRoutingActions;
use App\Filament\Resources\WorkshopRoutings\WorkshopRoutingResource;
use App\Services\WorkshopRoutingService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWorkshopRouting extends EditRecord
{
    protected static string $resource = WorkshopRoutingResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(WorkshopRoutingService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            WorkshopRoutingActions::activate(),
            WorkshopRoutingActions::deactivate(),
        ];
    }
}
