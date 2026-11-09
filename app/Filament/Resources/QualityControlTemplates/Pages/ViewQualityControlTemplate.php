<?php

namespace App\Filament\Resources\QualityControlTemplates\Pages;

use App\Filament\Resources\QualityControlTemplates\Actions\QualityControlTemplateActions;
use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQualityControlTemplate extends ViewRecord
{
    protected static string $resource = QualityControlTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            QualityControlTemplateActions::deactivate(),
            QualityControlTemplateActions::startNewVersion(),
        ];
    }
}
