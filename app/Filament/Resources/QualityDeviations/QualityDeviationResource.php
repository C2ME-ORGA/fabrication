<?php

namespace App\Filament\Resources\QualityDeviations;

use App\Filament\Resources\QualityDeviations\Pages\CreateQualityDeviation;
use App\Filament\Resources\QualityDeviations\Pages\ListQualityDeviations;
use App\Filament\Resources\QualityDeviations\Pages\ViewQualityDeviation;
use App\Filament\Resources\QualityDeviations\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\QualityDeviations\Schemas\QualityDeviationForm;
use App\Filament\Resources\QualityDeviations\Schemas\QualityDeviationInfolist;
use App\Filament\Resources\QualityDeviations\Tables\QualityDeviationsTable;
use App\Models\QualityDeviation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QualityDeviationResource extends Resource
{
    protected static ?string $model = QualityDeviation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $navigationLabel = 'Dérogations qualité';

    protected static ?string $modelLabel = 'dérogation qualité';

    protected static ?string $pluralModelLabel = 'dérogations qualité';

    protected static ?string $slug = 'quality-deviations';

    public static function form(Schema $schema): Schema
    {
        return QualityDeviationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QualityDeviationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QualityDeviationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['workOrder', 'requester', 'granter', 'targets.control', 'targets.point', 'targets.nonConformity']);
    }

    public static function getRelations(): array
    {
        return [
            AttachmentsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'work_order_number', 'reason'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var QualityDeviation $record */
        return [
            'OF' => $record->work_order_number ?? '—',
            'Statut' => $record->status->getLabel(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQualityDeviations::route('/'),
            'create' => CreateQualityDeviation::route('/create'),
            'view' => ViewQualityDeviation::route('/{record}'),
        ];
    }
}
