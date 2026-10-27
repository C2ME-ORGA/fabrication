<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Services\DocumentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    public function mount(): void
    {
        parent::mount();

        $jobId = request()->integer('job_id');

        if ($jobId > 0) {
            $this->form->fill(['job_id' => $jobId]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(DocumentService::class)->create($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        /** @var Document $record */
        $record = $this->getRecord();

        return DocumentResource::getUrl('view', ['record' => $record]);
    }
}
