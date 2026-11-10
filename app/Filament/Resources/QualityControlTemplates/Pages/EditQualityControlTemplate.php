<?php

namespace App\Filament\Resources\QualityControlTemplates\Pages;

use App\Filament\Resources\QualityControlTemplates\Actions\QualityControlTemplateActions;
use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use App\Services\QualityControlTemplateService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditQualityControlTemplate extends EditRecord
{
    protected static string $resource = QualityControlTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(QualityControlTemplateService::class)->update($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            QualityControlTemplateActions::deactivate(),
            QualityControlTemplateActions::startNewVersion(),
        ];
    }
}
