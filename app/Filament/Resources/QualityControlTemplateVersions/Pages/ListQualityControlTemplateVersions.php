<?php

namespace App\Filament\Resources\QualityControlTemplateVersions\Pages;

use App\Filament\Resources\QualityControlTemplateVersions\QualityControlTemplateVersionResource;
use Filament\Resources\Pages\ListRecords;

class ListQualityControlTemplateVersions extends ListRecords
{
    protected static string $resource = QualityControlTemplateVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
