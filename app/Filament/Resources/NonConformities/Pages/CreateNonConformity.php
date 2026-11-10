<?php

namespace App\Filament\Resources\NonConformities\Pages;

use App\Filament\Resources\NonConformities\NonConformityResource;
use App\Models\NonConformity;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NonConformityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateNonConformity extends CreateRecord
{
    protected static string $resource = NonConformityResource::class;

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

        return app(NonConformityService::class)->createManual(
            $workOrder,
            $data,
            $actor,
        );
    }

    protected function getRedirectUrl(): string
    {
        /** @var NonConformity $record */
        $record = $this->getRecord();

        return NonConformityResource::getUrl('view', ['record' => $record]);
    }
}
