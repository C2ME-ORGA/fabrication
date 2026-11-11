<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\WorkOrder;
use App\Services\ShipmentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateShipment extends CreateRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $workOrder = WorkOrder::query()->findOrFail($data['work_order_id']);

        return app(ShipmentService::class)->create(
            $workOrder,
            auth()->user(),
            Arr::except($data, ['work_order_id']),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
