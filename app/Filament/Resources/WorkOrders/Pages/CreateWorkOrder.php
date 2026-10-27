<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    public function mount(): void
    {
        parent::mount();

        $jobId = request()->integer('job_id');

        if ($jobId > 0) {
            $this->form->fill([
                'job_id' => $jobId,
            ]);
        }
    }

    /**
     * The number, status and ownership fields are generated server-side by
     * WorkOrderService, never taken from the form payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(WorkOrderService::class)->create($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        /** @var WorkOrder $record */
        $record = $this->getRecord();

        return WorkOrderResource::getUrl('view', ['record' => $record]);
    }
}
