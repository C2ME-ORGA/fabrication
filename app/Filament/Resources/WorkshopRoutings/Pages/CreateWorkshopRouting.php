<?php

namespace App\Filament\Resources\WorkshopRoutings\Pages;

use App\Filament\Resources\WorkshopRoutings\WorkshopRoutingResource;
use App\Models\WorkshopRouting;
use App\Services\WorkshopRoutingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkshopRouting extends CreateRecord
{
    protected static string $resource = WorkshopRoutingResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(WorkshopRoutingService::class)->create($data);
    }

    protected function getRedirectUrl(): string
    {
        /** @var WorkshopRouting $record */
        $record = $this->getRecord();

        return WorkshopRoutingResource::getUrl('view', ['record' => $record]);
    }
}
