<?php

namespace App\Filament\Resources\QualityDeviations\Pages;

use App\Filament\Resources\QualityDeviations\QualityDeviationResource;
use App\Models\QualityDeviation;
use App\Models\WorkOrder;
use App\Services\QualityDeviationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQualityDeviation extends CreateRecord
{
    protected static string $resource = QualityDeviationResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $workOrder = WorkOrder::query()->findOrFail($data['work_order_id']);

        return app(QualityDeviationService::class)->request($workOrder, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        /** @var QualityDeviation $record */
        $record = $this->getRecord();

        return QualityDeviationResource::getUrl('view', ['record' => $record]);
    }
}
