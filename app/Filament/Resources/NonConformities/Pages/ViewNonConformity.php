<?php

namespace App\Filament\Resources\NonConformities\Pages;

use App\Filament\Resources\NonConformities\Actions\NonConformityActions;
use App\Filament\Resources\NonConformities\NonConformityResource;
use Filament\Resources\Pages\ViewRecord;

class ViewNonConformity extends ViewRecord
{
    protected static string $resource = NonConformityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            NonConformityActions::qualify(),
            NonConformityActions::assign(),
            NonConformityActions::start(),
            NonConformityActions::submitForVerification(),
            NonConformityActions::close(),
            NonConformityActions::reopen(),
            NonConformityActions::cancel(),
        ];
    }
}
