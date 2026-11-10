<?php

namespace App\Filament\Workshop\Resources\QualityControls\Schemas;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityRequirementScope;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkOrderQualityControlPoint;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Formulaire partagé de saisie d'un résultat qualité.
 */
final class QualityControlResultForm
{
    /**
     * @return array<int, mixed>
     */
    public static function schema(
        WorkOrderQualityControl $control,
        ?WorkOrderQualityControlPoint $fixedPoint = null,
    ): array {
        return [
            Select::make('point_id')
                ->label('Point de contrôle')
                ->options(fn (): array => $control->points
                    ->mapWithKeys(fn (WorkOrderQualityControlPoint $point): array => [
                        $point->getKey() => self::pointLabel($point),
                    ])
                    ->all())
                ->default($fixedPoint?->getKey())
                ->disabled($fixedPoint !== null)
                ->dehydrated(true)
                ->required()
                ->native(false)
                ->live()
                ->visible($fixedPoint === null),
            Select::make('work_order_item_id')
                ->label('Repère / pièce')
                ->options(fn (Get $get): array => self::itemOptions($control, self::selectedPoint($control, $get, $fixedPoint)))
                ->searchable()
                ->native(false)
                ->visible(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->label !== null
                    && $control->scope === QualityRequirementScope::Item)
                ->required(fn (): bool => $control->scope === QualityRequirementScope::Item),
            Select::make('value_boolean')
                ->label('Conformité')
                ->options(['1' => 'Conforme', '0' => 'Non conforme'])
                ->native(false)
                ->visible(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Conformity)
                ->required(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Conformity),
            TextInput::make('value_numeric')
                ->label('Mesure')
                ->numeric()
                ->suffix(fn (Get $get): ?string => self::selectedPoint($control, $get, $fixedPoint)?->unit)
                ->helperText(fn (Get $get): ?string => self::toleranceHint(self::selectedPoint($control, $get, $fixedPoint)))
                ->visible(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Numeric)
                ->required(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Numeric),
            Textarea::make('value_text')
                ->label('Texte constaté')
                ->rows(3)
                ->visible(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Text)
                ->required(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Text),
            Select::make('is_conform')
                ->label('Conformité constatée')
                ->options(['1' => 'Conforme', '0' => 'Non conforme'])
                ->native(false)
                ->helperText('Le texte ne vaut pas conformité : la qualification est obligatoire.')
                ->visible(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Text)
                ->required(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Text),
            Select::make('value_choice')
                ->label('Option constatée')
                ->options(fn (Get $get): array => collect(self::selectedPoint($control, $get, $fixedPoint)?->options ?? [])
                    ->mapWithKeys(fn (array $option): array => [
                        (string) ($option['value'] ?? '') => (string) ($option['label'] ?? ($option['value'] ?? '')),
                    ])
                    ->all())
                ->native(false)
                ->visible(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Choice)
                ->required(fn (Get $get): bool => self::selectedPoint($control, $get, $fixedPoint)?->result_type === QualityControlResultType::Choice),
            Textarea::make('comment')
                ->label('Commentaire')
                ->rows(2)
                ->maxLength(2000),
            FileUpload::make('evidence')
                ->label('Preuves')
                ->multiple()
                ->storeFiles(false)
                ->maxSize((int) config('quality.evidence.max_size_kb', 25600)),
        ];
    }

    public static function selectedPoint(
        WorkOrderQualityControl $control,
        Get $get,
        ?WorkOrderQualityControlPoint $fixedPoint = null,
    ): ?WorkOrderQualityControlPoint {
        if ($fixedPoint !== null) {
            return $fixedPoint;
        }

        return $control->points->firstWhere('id', (int) ($get('point_id') ?? 0));
    }

    /**
     * @return array<int, string>
     */
    public static function itemOptions(
        WorkOrderQualityControl $control,
        ?WorkOrderQualityControlPoint $point,
    ): array {
        if ($control->workOrder === null) {
            return [];
        }

        $sampledIds = $point?->sampling_mode === QualityControlSamplingMode::EveryN
            ? $point->sampledItemIds()
            : [];

        $query = $control->workOrder->items()->orderBy('mark');

        if ($sampledIds !== []) {
            $query->whereIn('id', $sampledIds);
        }

        return $query->get()->mapWithKeys(fn ($item): array => [
            $item->getKey() => $item->mark.' — '.$item->designation,
        ])->all();
    }

    public static function pointLabel(WorkOrderQualityControlPoint $point): string
    {
        $suffix = $point->is_critical ? ' · critique' : '';

        return $point->label.' ('.$point->result_type->getLabel().')'.$suffix;
    }

    public static function toleranceHint(?WorkOrderQualityControlPoint $point): ?string
    {
        if ($point === null || $point->result_type !== QualityControlResultType::Numeric) {
            return null;
        }

        $min = $point->tolerance_min ?? '−∞';
        $max = $point->tolerance_max ?? '+∞';

        return 'Tolérance : ['.$min.' ; '.$max.'] '.($point->unit ?? '');
    }
}
