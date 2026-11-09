<?php

namespace App\Filament\Workshop\Resources\WorkshopOperations;

use App\Enums\WorkOrderStatus;
use App\Filament\Workshop\Resources\WorkshopOperations\Pages\ListWorkshopOperations;
use App\Filament\Workshop\Resources\WorkshopOperations\Pages\ViewWorkshopOperation;
use App\Filament\Workshop\Resources\WorkshopOperations\Schemas\WorkshopOperationInfolist;
use App\Filament\Workshop\Resources\WorkshopOperations\Tables\WorkshopOperationsTable;
use App\Models\WorkOrderOperation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkshopOperationResource extends Resource
{
    protected static ?string $model = WorkOrderOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Opérations atelier';

    protected static ?string $modelLabel = 'opération atelier';

    protected static ?string $pluralModelLabel = 'opérations atelier';

    protected static ?string $slug = 'operations';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return WorkshopOperationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkshopOperationInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('workOrder', fn (Builder $query): Builder => $query->whereIn('status', [
                WorkOrderStatus::Released->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::QualityCheck->value,
            ]))
            ->with([
                'workOrder.job',
                'dependencies',
                'timeEntries.user',
                'auditLogs.user',
                'lastStatusAudit.user',
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'workOrder.number', 'workOrder.job.code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'OF' => $record->workOrder?->number,
            'Statut' => $record->status->getLabel(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkshopOperations::route('/'),
            'view' => ViewWorkshopOperation::route('/{record}'),
        ];
    }
}
