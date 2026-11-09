<?php

namespace App\Filament\Resources\WorkshopRoutings;

use App\Filament\Resources\WorkshopRoutings\Pages\CreateWorkshopRouting;
use App\Filament\Resources\WorkshopRoutings\Pages\EditWorkshopRouting;
use App\Filament\Resources\WorkshopRoutings\Pages\ListWorkshopRoutings;
use App\Filament\Resources\WorkshopRoutings\Pages\ViewWorkshopRouting;
use App\Filament\Resources\WorkshopRoutings\RelationManagers\DependenciesRelationManager;
use App\Filament\Resources\WorkshopRoutings\RelationManagers\OperationsRelationManager;
use App\Filament\Resources\WorkshopRoutings\Schemas\WorkshopRoutingForm;
use App\Filament\Resources\WorkshopRoutings\Schemas\WorkshopRoutingInfolist;
use App\Filament\Resources\WorkshopRoutings\Tables\WorkshopRoutingsTable;
use App\Models\WorkshopRouting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkshopRoutingResource extends Resource
{
    protected static ?string $model = WorkshopRouting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Gammes atelier';

    protected static ?string $modelLabel = 'gamme atelier';

    protected static ?string $pluralModelLabel = 'gammes atelier';

    protected static ?string $slug = 'workshop-routings';

    public static function form(Schema $schema): Schema
    {
        return WorkshopRoutingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkshopRoutingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkshopRoutingsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('operations')
            ->withSum('operations', 'estimated_duration_minutes');
    }

    public static function getRelations(): array
    {
        return [
            OperationsRelationManager::class,
            DependenciesRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Statut' => $record->isActive() ? 'Active' : 'Inactive',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkshopRoutings::route('/'),
            'create' => CreateWorkshopRouting::route('/create'),
            'view' => ViewWorkshopRouting::route('/{record}'),
            'edit' => EditWorkshopRouting::route('/{record}/edit'),
        ];
    }
}
