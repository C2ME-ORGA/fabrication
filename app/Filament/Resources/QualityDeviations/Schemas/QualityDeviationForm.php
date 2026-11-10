<?php

namespace App\Filament\Resources\QualityDeviations\Schemas;

use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\QualityControlStatus;
use App\Enums\QualityControlType;
use App\Enums\QualityDeviationScope;
use App\Enums\QualityRequirementScope;
use App\Enums\WorkOrderStatus;
use App\Models\NonConformity;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class QualityDeviationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Demande')
                    ->columns(2)
                    ->schema([
                        Select::make('work_order_id')
                            ->label('Ordre de fabrication')
                            ->options(fn (): array => WorkOrder::query()
                                ->whereIn('status', [
                                    WorkOrderStatus::Released->value,
                                    WorkOrderStatus::InProgress->value,
                                    WorkOrderStatus::QualityCheck->value,
                                ])
                                ->orderByDesc('id')
                                ->pluck('number', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('scope')
                            ->label('Type de cible')
                            ->options([
                                QualityDeviationScope::Control->value => QualityDeviationScope::Control->getLabel(),
                                QualityDeviationScope::ControlPoint->value => QualityDeviationScope::ControlPoint->getLabel(),
                                QualityDeviationScope::NonConformity->value => QualityDeviationScope::NonConformity->getLabel(),
                            ])
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('work_order_quality_control_id')
                            ->label('Contrôle qualité')
                            ->options(fn (Get $get): array => WorkOrderQualityControl::query()
                                ->where('work_order_id', $get('work_order_id'))
                                ->where('status', '!=', QualityControlStatus::Cancelled->value)
                                ->orderBy('position')
                                ->get()
                                ->filter(fn (WorkOrderQualityControl $control): bool => ! (
                                    $control->control_type === QualityControlType::Final
                                    && $control->scope === QualityRequirementScope::WorkOrder
                                ))
                                ->mapWithKeys(fn (WorkOrderQualityControl $control): array => [
                                    $control->getKey() => $control->template_code.' — '.$control->template_name,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('scope') === QualityDeviationScope::Control->value)
                            ->required(fn (Get $get): bool => $get('scope') === QualityDeviationScope::Control->value),
                        Select::make('work_order_quality_control_point_id')
                            ->label('Point de contrôle')
                            ->options(fn (Get $get): array => WorkOrderQualityControlPoint::query()
                                ->whereHas('control', fn ($query) => $query->where('work_order_id', $get('work_order_id')))
                                ->where('allow_deviation', true)
                                ->orderBy('position')
                                ->get()
                                ->mapWithKeys(fn (WorkOrderQualityControlPoint $point): array => [
                                    $point->getKey() => $point->label,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('scope') === QualityDeviationScope::ControlPoint->value)
                            ->required(fn (Get $get): bool => $get('scope') === QualityDeviationScope::ControlPoint->value),
                        Select::make('non_conformity_id')
                            ->label('Non-conformité')
                            ->options(fn (Get $get): array => NonConformity::query()
                                ->where('work_order_id', $get('work_order_id'))
                                ->where('severity', '!=', NonConformitySeverity::Critical->value)
                                ->whereNotIn('status', [
                                    NonConformityStatus::Closed->value,
                                    NonConformityStatus::Cancelled->value,
                                ])
                                ->orderByDesc('id')
                                ->pluck('reference', 'id')
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('scope') === QualityDeviationScope::NonConformity->value)
                            ->required(fn (Get $get): bool => $get('scope') === QualityDeviationScope::NonConformity->value),
                        DateTimePicker::make('expires_at')
                            ->label('Expiration')
                            ->after('now')
                            ->helperText('Laisser vide pour une dérogation sans expiration automatique.'),
                    ]),
                Section::make('Justification')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Motif détaillé')
                            ->required()
                            ->minLength(max(20, (int) config('quality.deviations.reason_min_length', 20)))
                            ->rows(4),
                        Textarea::make('description')
                            ->label('Justification métier complémentaire')
                            ->rows(3),
                    ]),
            ]);
    }
}
