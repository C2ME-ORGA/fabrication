<?php

namespace App\Filament\Resources\WorkOrders;

use App\Enums\NonConformityStatus;
use App\Enums\QualityControlStatus;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\BomImportsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\GeneratedDocumentsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\NonConformitiesRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\QualityControlsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\QualityDeviationsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\QualityReleasesRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\ShipmentsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\WorkOrderOperationsRelationManager;
use App\Filament\Resources\WorkOrders\Schemas\WorkOrderForm;
use App\Filament\Resources\WorkOrders\Schemas\WorkOrderInfolist;
use App\Filament\Resources\WorkOrders\Tables\WorkOrdersTable;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Ordres de fabrication';

    protected static ?string $modelLabel = 'ordre de fabrication';

    protected static ?string $pluralModelLabel = 'ordres de fabrication';

    protected static ?string $slug = 'work-orders';

    public static function form(Schema $schema): Schema
    {
        return WorkOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'qualityControls as quality_controls_pending_count' => fn (Builder $query): Builder => $query->whereIn('status', [
                QualityControlStatus::Pending->value,
                QualityControlStatus::InProgress->value,
            ]),
            'nonConformities as open_non_conformities_count' => fn (Builder $query): Builder => $query->whereNotIn('status', [
                NonConformityStatus::Closed->value,
                NonConformityStatus::Cancelled->value,
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            WorkOrderOperationsRelationManager::class,
            ItemsRelationManager::class,
            GeneratedDocumentsRelationManager::class,
            ShipmentsRelationManager::class,
            QualityControlsRelationManager::class,
            QualityReleasesRelationManager::class,
            NonConformitiesRelationManager::class,
            QualityDeviationsRelationManager::class,
            BomImportsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'title', 'job.code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Affaire' => $record->job->code,
            'Statut' => $record->status->getLabel(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'create' => CreateWorkOrder::route('/create'),
            'view' => ViewWorkOrder::route('/{record}'),
            'edit' => EditWorkOrder::route('/{record}/edit'),
        ];
    }
}
