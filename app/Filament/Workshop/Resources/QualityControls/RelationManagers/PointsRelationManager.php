<?php

namespace App\Filament\Workshop\Resources\QualityControls\RelationManagers;

use App\DTO\QualityControlProgress;
use App\Filament\Workshop\Resources\QualityControls\Actions\QualityControlActions;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use App\Services\QualityControlEvaluationService;
use App\Services\QualityControlSamplingService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PointsRelationManager extends RelationManager
{
    protected static string $relationship = 'points';

    protected static ?string $title = 'Points de contrôle';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof WorkOrderQualityControl
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->width('70px'),
                TextColumn::make('label')
                    ->label('Point')
                    ->wrap()
                    ->weight('bold'),
                TextColumn::make('result_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('sampling')
                    ->label('Échantillonnage')
                    ->state(fn (WorkOrderQualityControlPoint $record): string => app(QualityControlSamplingService::class)->describe(
                        $record->sampling_mode,
                        $record->sampling_every,
                        $record->required_results,
                    )),
                TextColumn::make('progress')
                    ->label('Avancement')
                    ->state(function (WorkOrderQualityControlPoint $record): string {
                        $control = $this->control();
                        /** @var QualityControlProgress $progress */
                        $progress = app(QualityControlEvaluationService::class)->pointProgress($control, $record);

                        return $progress->conformingResults.' / '.$progress->requiredResults;
                    })
                    ->badge()
                    ->color(fn (WorkOrderQualityControlPoint $record): string => $this->progressColor($record)),
                IconColumn::make('is_mandatory')
                    ->label('Obligatoire')
                    ->boolean(),
                IconColumn::make('is_critical')
                    ->label('Critique')
                    ->boolean(),
                IconColumn::make('allow_deviation')
                    ->label('Dérogation')
                    ->boolean(),
                TextColumn::make('last_result')
                    ->label('Dernier résultat')
                    ->state(fn (WorkOrderQualityControlPoint $record): string => $record->results->last()?->displayValue() ?? '—'),
            ])
            ->headerActions([])
            ->recordActions([
                QualityControlActions::recordResultForPoint(),
            ])
            ->toolbarActions([]);
    }

    protected function progressColor(WorkOrderQualityControlPoint $point): string
    {
        $progress = app(QualityControlEvaluationService::class)->pointProgress($this->control(), $point);

        if ($progress->hasNonConformity) {
            return 'danger';
        }

        if ($progress->satisfied) {
            return 'success';
        }

        return 'warning';
    }

    protected function control(): WorkOrderQualityControl
    {
        /** @var WorkOrderQualityControl $control */
        $control = $this->getOwnerRecord();

        return $control;
    }
}
