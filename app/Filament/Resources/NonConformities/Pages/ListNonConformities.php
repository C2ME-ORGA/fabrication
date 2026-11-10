<?php

namespace App\Filament\Resources\NonConformities\Pages;

use App\Filament\Resources\NonConformities\NonConformityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNonConformities extends ListRecords
{
    protected static string $resource = NonConformityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Déclarer une NC'),
        ];
    }
}
