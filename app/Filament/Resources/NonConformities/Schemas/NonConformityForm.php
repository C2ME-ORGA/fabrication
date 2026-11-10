<?php

namespace App\Filament\Resources\NonConformities\Schemas;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformitySeverity;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderOperation;
use App\Models\WorkOrderQualityControl;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class NonConformityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rattachement')
                    ->columns(2)
                    ->schema([
                        Select::make('work_order_id')
                            ->label('Ordre de fabrication')
                            ->options(function (): array {
                                $query = WorkOrder::query();

                                if (auth()->user()?->canManageNonConformities() !== true) {
                                    $query->whereIn('status', [
                                        WorkOrderStatus::Released->value,
                                        WorkOrderStatus::InProgress->value,
                                        WorkOrderStatus::QualityCheck->value,
                                    ]);
                                }

                                return $query->orderByDesc('id')->pluck('number', 'id')->all();
                            })
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('work_order_operation_id')
                            ->label('Opération')
                            ->options(fn (Get $get): array => WorkOrderOperation::query()
                                ->where('work_order_id', $get('work_order_id'))
                                ->orderBy('position')
                                ->get()
                                ->mapWithKeys(fn (WorkOrderOperation $operation): array => [
                                    $operation->getKey() => $operation->code.' — '.$operation->name,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->live(),
                        Select::make('work_order_item_id')
                            ->label('Repère / pièce')
                            ->options(fn (Get $get): array => WorkOrderItem::query()
                                ->where('work_order_id', $get('work_order_id'))
                                ->orderBy('mark')
                                ->get()
                                ->mapWithKeys(fn (WorkOrderItem $item): array => [
                                    $item->getKey() => $item->mark.' — '.$item->designation,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                        Select::make('work_order_quality_control_id')
                            ->label('Contrôle qualité')
                            ->options(fn (Get $get): array => WorkOrderQualityControl::query()
                                ->where('work_order_id', $get('work_order_id'))
                                ->orderBy('position')
                                ->get()
                                ->mapWithKeys(fn (WorkOrderQualityControl $control): array => [
                                    $control->getKey() => $control->template_code.' — '.$control->template_name,
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                    ]),
                Section::make('Anomalie')
                    ->columns(2)
                    ->schema([
                        Select::make('severity')
                            ->label('Gravité')
                            ->options(NonConformitySeverity::options())
                            ->required()
                            ->native(false),
                        Select::make('category')
                            ->label('Catégorie')
                            ->options(NonConformityCategory::options())
                            ->required()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Description factuelle')
                            ->required()
                            ->minLength(10)
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('criterion')
                            ->label('Exigence ou critère non respecté')
                            ->rows(3)
                            ->columnSpanFull(),
                        DateTimePicker::make('detected_at')
                            ->label('Détectée le')
                            ->default(now())
                            ->required()
                            ->maxDate(now()),
                        DatePicker::make('due_date')
                            ->label('Échéance')
                            ->helperText('Calculée depuis la politique de gravité si laissée vide.'),
                    ]),
                Section::make('Traitement')
                    ->columns(2)
                    ->schema([
                        Select::make('responsible_id')
                            ->label('Responsable désigné')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('name', 'like', '%'.$search.'%')
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn (int|string|null $value): ?string => $value === null
                                ? null
                                : User::query()->whereKey($value)->value('name'))
                            ->native(false),
                        Textarea::make('treatment_plan')
                            ->label('Traitement envisagé')
                            ->rows(3),
                        Textarea::make('immediate_action')
                            ->label('Action immédiate / confinement')
                            ->rows(3),
                        DateTimePicker::make('containment_at')
                            ->label('Confinement réalisé le')
                            ->maxDate(now()),
                        Textarea::make('root_cause')
                            ->label('Cause racine')
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('root_cause_method')
                            ->label('Méthode d\'analyse')
                            ->options([
                                '5_why' => '5 pourquoi',
                                'ishikawa' => 'Ishikawa',
                                '8d' => '8D',
                                'other' => 'Autre',
                            ])
                            ->native(false),
                    ]),
            ]);
    }
}
