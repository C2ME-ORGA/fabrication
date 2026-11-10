<?php

namespace App\Filament\Workshop\Resources\QualityControls\Pages;

use App\Filament\Workshop\Resources\QualityControls\Actions\QualityControlActions;
use App\Filament\Workshop\Resources\QualityControls\QualityControlResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQualityControl extends ViewRecord
{
    protected static string $resource = QualityControlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            QualityControlActions::recordResult(),
            QualityControlActions::validateResult(),
            QualityControlActions::secondValidation(),
            QualityControlActions::declareNonConformity(),
            QualityControlActions::cancel(),
        ];
    }
}
