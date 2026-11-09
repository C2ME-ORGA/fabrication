<?php

namespace App\Filament\Resources\QualityControlTemplates;

use App\Filament\Resources\QualityControlTemplates\Pages\CreateQualityControlTemplate;
use App\Filament\Resources\QualityControlTemplates\Pages\EditQualityControlTemplate;
use App\Filament\Resources\QualityControlTemplates\Pages\ListQualityControlTemplates;
use App\Filament\Resources\QualityControlTemplates\Pages\ViewQualityControlTemplate;
use App\Filament\Resources\QualityControlTemplates\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\QualityControlTemplates\Schemas\QualityControlTemplateForm;
use App\Filament\Resources\QualityControlTemplates\Schemas\QualityControlTemplateInfolist;
use App\Filament\Resources\QualityControlTemplates\Tables\QualityControlTemplatesTable;
use App\Models\QualityControlTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QualityControlTemplateResource extends Resource
{
    protected static ?string $model = QualityControlTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Modèles de contrôle';

    protected static ?string $modelLabel = 'modèle de contrôle';

    protected static ?string $pluralModelLabel = 'modèles de contrôle';

    protected static ?string $slug = 'quality-control-templates';

    public static function form(Schema $schema): Schema
    {
        return QualityControlTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QualityControlTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QualityControlTemplatesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('versions');
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Statut' => $record->isActive() ? 'Actif' : 'Inactif',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQualityControlTemplates::route('/'),
            'create' => CreateQualityControlTemplate::route('/create'),
            'view' => ViewQualityControlTemplate::route('/{record}'),
            'edit' => EditQualityControlTemplate::route('/{record}/edit'),
        ];
    }
}
