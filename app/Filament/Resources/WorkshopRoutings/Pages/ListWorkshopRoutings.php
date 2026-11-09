<?php

namespace App\Filament\Resources\WorkshopRoutings\Pages;

use App\Filament\Resources\WorkshopRoutings\WorkshopRoutingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkshopRoutings extends ListRecords
{
    protected static string $resource = WorkshopRoutingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
