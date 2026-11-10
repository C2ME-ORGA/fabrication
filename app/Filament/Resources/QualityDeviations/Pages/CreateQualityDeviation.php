<?php

namespace App\Filament\Resources\QualityDeviations\Pages;

use App\Filament\Resources\QualityDeviations\QualityDeviationResource;
use App\Models\QualityDeviation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityDeviationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateQualityDeviation extends CreateRecord
{
    protected static string $resource = QualityDeviationResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        $workOrder = WorkOrder::query()->findOrFail($data['work_order_id']);

        if (! $workOrder->isAccessibleBy($actor)) {
            throw ValidationException::withMessages([
                'work_order_id' => 'Cet ordre de fabrication n\'est pas accessible avec vos habilitations.',
            ]);
        }

        return app(QualityDeviationService::class)->request($workOrder, $data, $actor);
    }

    protected function getRedirectUrl(): string
    {
        /** @var QualityDeviation $record */
        $record = $this->getRecord();

        return QualityDeviationResource::getUrl('view', ['record' => $record]);
    }
}
