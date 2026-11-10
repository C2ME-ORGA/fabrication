<?php

namespace App\Filament\Resources\QualityControlTemplateVersions\Pages;

use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use App\Filament\Resources\QualityControlTemplateVersions\Actions\QualityControlTemplateVersionActions;
use App\Filament\Resources\QualityControlTemplateVersions\QualityControlTemplateVersionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewQualityControlTemplateVersion extends ViewRecord
{
    protected static string $resource = QualityControlTemplateVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToTemplate')
                ->label('Retour au modèle')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => QualityControlTemplateResource::getUrl('view', [
                    'record' => $this->getRecord()->quality_control_template_id,
                ])),
            QualityControlTemplateVersionActions::activate(),
            QualityControlTemplateVersionActions::deleteDraft(),
        ];
    }
}
