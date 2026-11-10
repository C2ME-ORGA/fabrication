<?php

namespace App\Filament\Resources\QualityDeviations\Pages;

use App\Filament\Resources\QualityDeviations\QualityDeviationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQualityDeviations extends ListRecords
{
    protected static string $resource = QualityDeviationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Demander une dérogation'),
        ];
    }
}
