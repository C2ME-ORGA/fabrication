<?php

namespace App\Filament\Workshop\Resources\QualityControls\Schemas;

use App\Models\NonConformity;
use App\Models\QualityDeviation;
use App\Models\WorkOrderQualityControl;
use App\Services\QualityClosureEligibilityService;
use App\Services\QualityControlEvaluationService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QualityControlInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contrôle qualité')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('workOrder.number')->label('OF'),
                        TextEntry::make('template_name')->label('Contrôle'),
                        TextEntry::make('template_code')->label('Code modèle'),
                        TextEntry::make('template_version_number')
                            ->label('Version')
                            ->formatStateUsing(fn (mixed $state): string => 'v'.$state),
                        TextEntry::make('control_type')->label('Type')->badge(),
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('scope')->label('Portée')->badge(),
                        TextEntry::make('trigger')->label('Déclencheur')->badge(),
                        TextEntry::make('operation.code')->label('Opération')->placeholder('—'),
                        TextEntry::make('progress')
                            ->label('Avancement')
                            ->state(fn (WorkOrderQualityControl $record): string => app(QualityControlEvaluationService::class)->summary($record))
                            ->badge(),
                        TextEntry::make('is_mandatory')
                            ->label('Obligatoire')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Oui' : 'Non'),
                        TextEntry::make('required_by_at')
                            ->label('Exigible depuis')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('completed_at')
                            ->label('Clôturé le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),
                Section::make('Éligibilité qualité de l’OF')
                    ->schema([
                        Html::make(function (WorkOrderQualityControl $record): string {
                            $workOrder = $record->workOrder;

                            if ($workOrder === null) {
                                return '<p class="text-sm text-gray-500">OF introuvable.</p>';
                            }

                            $eligibility = app(QualityClosureEligibilityService::class)->evaluate($workOrder);

                            return view('filament.work-orders.quality-eligibility', [
                                'workOrder' => $workOrder,
                                'eligibility' => $eligibility,
                                'controls' => WorkOrderQualityControl::query()->whereKey($eligibility->controlIds)->get(),
                                'nonConformities' => NonConformity::query()->whereKey($eligibility->nonConformityIds)->get(),
                                'deviations' => QualityDeviation::query()->whereKey($eligibility->deviationIds)->get(),
                            ])->render();
                        }),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
