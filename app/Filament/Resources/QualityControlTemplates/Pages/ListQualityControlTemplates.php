<?php

namespace App\Filament\Resources\QualityControlTemplates\Pages;

use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQualityControlTemplates extends ListRecords
{
    protected static string $resource = QualityControlTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
