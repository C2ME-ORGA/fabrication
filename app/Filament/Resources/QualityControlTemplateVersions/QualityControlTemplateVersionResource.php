<?php

namespace App\Filament\Resources\QualityControlTemplateVersions;

use App\Filament\Resources\QualityControlTemplateVersions\Pages\ListQualityControlTemplateVersions;
use App\Filament\Resources\QualityControlTemplateVersions\Pages\ViewQualityControlTemplateVersion;
use App\Filament\Resources\QualityControlTemplateVersions\RelationManagers\PointsRelationManager;
use App\Filament\Resources\QualityControlTemplateVersions\Schemas\QualityControlTemplateVersionInfolist;
use App\Filament\Resources\QualityControlTemplateVersions\Tables\QualityControlTemplateVersionsTable;
use App\Models\QualityControlTemplateVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QualityControlTemplateVersionResource extends Resource
{
    protected static ?string $model = QualityControlTemplateVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    /**
     * Ressource accessible par lien depuis le modèle de contrôle. Elle n'a
     * pas sa propre entrée de navigation : le référentiel se consulte depuis
     * le modèle, et les Policies contrôlent l'accès direct à l'URL.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Versions de modèle';

    protected static ?string $modelLabel = 'version de modèle';

    protected static ?string $pluralModelLabel = 'versions de modèle';

    protected static ?string $slug = 'quality-control-template-versions';

    protected static ?string $recordTitleAttribute = 'version_number';

    public static function infolist(Schema $schema): Schema
    {
        return QualityControlTemplateVersionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QualityControlTemplateVersionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['template'])->withCount('points');
    }

    public static function getRelations(): array
    {
        return [
            PointsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQualityControlTemplateVersions::route('/'),
            'view' => ViewQualityControlTemplateVersion::route('/{record}'),
        ];
    }
}
