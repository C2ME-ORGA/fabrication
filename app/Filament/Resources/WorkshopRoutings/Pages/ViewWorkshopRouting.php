<?php

namespace App\Filament\Resources\WorkshopRoutings\Pages;

use App\Filament\Resources\WorkshopRoutings\Actions\WorkshopRoutingActions;
use App\Filament\Resources\WorkshopRoutings\WorkshopRoutingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkshopRouting extends ViewRecord
{
    protected static string $resource = WorkshopRoutingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            WorkshopRoutingActions::activate(),
            WorkshopRoutingActions::deactivate(),
        ];
    }
}
