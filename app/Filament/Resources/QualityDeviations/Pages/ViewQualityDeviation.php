<?php

namespace App\Filament\Resources\QualityDeviations\Pages;

use App\Filament\Resources\QualityDeviations\Actions\QualityDeviationActions;
use App\Filament\Resources\QualityDeviations\QualityDeviationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQualityDeviation extends ViewRecord
{
    protected static string $resource = QualityDeviationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            QualityDeviationActions::approve(),
            QualityDeviationActions::reject(),
            QualityDeviationActions::revoke(),
        ];
    }
}
