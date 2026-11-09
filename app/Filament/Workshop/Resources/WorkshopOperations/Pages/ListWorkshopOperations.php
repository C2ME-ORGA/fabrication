<?php

namespace App\Filament\Workshop\Resources\WorkshopOperations\Pages;

use App\Filament\Workshop\Resources\WorkshopOperations\WorkshopOperationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListWorkshopOperations extends ListRecords
{
    protected static string $resource = WorkshopOperationResource::class;

    public function getHeader(): ?View
    {
        return view('filament.workshop.partials.active-timers-header');
    }
}
