<?php

namespace App\Filament\Workshop\Resources\QualityControls;

use App\Enums\WorkOrderStatus;
use App\Filament\Workshop\Resources\QualityControls\Pages\ListQualityControls;
use App\Filament\Workshop\Resources\QualityControls\Pages\ViewQualityControl;
use App\Filament\Workshop\Resources\QualityControls\RelationManagers\PointsRelationManager;
use App\Filament\Workshop\Resources\QualityControls\RelationManagers\ResultsRelationManager;
use App\Filament\Workshop\Resources\QualityControls\Schemas\QualityControlInfolist;
use App\Filament\Workshop\Resources\QualityControls\Tables\QualityControlsTable;
use App\Models\WorkOrderQualityControl;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QualityControlResource extends Resource
{
    protected static ?string $model = WorkOrderQualityControl::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'template_name';

    protected static ?string $navigationLabel = 'Contrôles qualité';

    protected static ?string $modelLabel = 'contrôle qualité';

    protected static ?string $pluralModelLabel = 'contrôles qualité';

    protected static ?string $slug = 'quality-controls';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return QualityControlsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QualityControlInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('workOrder', fn (Builder $query): Builder => $query->whereIn(
                'status',
                self::visibleWorkOrderStatuses(),
            ))
            ->with(['workOrder.job', 'operation', 'item', 'points.results.validations']);
    }

    /**
     * @return array<int, string>
     */
    public static function visibleWorkOrderStatuses(): array
    {
        $statuses = [
            WorkOrderStatus::Released->value,
            WorkOrderStatus::InProgress->value,
            WorkOrderStatus::QualityCheck->value,
        ];

        if (auth()->user()?->canViewQualityHistory() === true) {
            $statuses[] = WorkOrderStatus::Completed->value;
            $statuses[] = WorkOrderStatus::Cancelled->value;
        }

        return $statuses;
    }

    public static function getRelations(): array
    {
        return [
            PointsRelationManager::class,
            ResultsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['template_code', 'template_name', 'workOrder.number', 'operation.code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var WorkOrderQualityControl $record */
        return [
            'OF' => $record->workOrder?->number ?? '—',
            'Statut' => $record->status->getLabel(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQualityControls::route('/'),
            'view' => ViewQualityControl::route('/{record}'),
        ];
    }
}
