<?php

namespace App\Filament\Resources\QualityControlTemplates\Pages;

use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use App\Models\QualityControlTemplate;
use App\Services\QualityControlTemplateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQualityControlTemplate extends CreateRecord
{
    protected static string $resource = QualityControlTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(QualityControlTemplateService::class)->create($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        /** @var QualityControlTemplate $record */
        $record = $this->getRecord();

        return QualityControlTemplateResource::getUrl('view', ['record' => $record]);
    }
}
