<?php

namespace App\Filament\Resources\NonConformities\Pages;

use App\Filament\Resources\NonConformities\NonConformityResource;
use App\Models\NonConformity;
use App\Models\WorkOrder;
use App\Services\NonConformityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateNonConformity extends CreateRecord
{
    protected static string $resource = NonConformityResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $workOrder = WorkOrder::query()->findOrFail($data['work_order_id']);

        return app(NonConformityService::class)->createManual(
            $workOrder,
            $data,
            auth()->user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        /** @var NonConformity $record */
        $record = $this->getRecord();

        return NonConformityResource::getUrl('view', ['record' => $record]);
    }
}
