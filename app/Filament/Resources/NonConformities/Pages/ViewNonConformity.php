<?php

namespace App\Filament\Resources\NonConformities\Pages;

use App\Filament\Resources\NonConformities\Actions\NonConformityActions;
use App\Filament\Resources\NonConformities\NonConformityResource;
use App\Models\NonConformity;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewNonConformity extends ViewRecord
{
    protected static string $resource = NonConformityResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        /** @var NonConformity $record */
        $record = parent::resolveRecord($key);

        return $record->load([
            'responsible',
            'operation',
            'item',
            'control',
            'result',
            'detector',
            'creator',
            'qualifier',
            'closer',
        ]);
    }

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
