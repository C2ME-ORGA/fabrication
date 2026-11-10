<?php

namespace App\Filament\Resources\NonConformities;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\NonConformities\Pages\CreateNonConformity;
use App\Filament\Resources\NonConformities\Pages\ListNonConformities;
use App\Filament\Resources\NonConformities\Pages\ViewNonConformity;
use App\Filament\Resources\NonConformities\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\NonConformities\RelationManagers\CorrectiveActionsRelationManager;
use App\Filament\Resources\NonConformities\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\NonConformities\RelationManagers\VerificationsRelationManager;
use App\Filament\Resources\NonConformities\Schemas\NonConformityForm;
use App\Filament\Resources\NonConformities\Schemas\NonConformityInfolist;
use App\Filament\Resources\NonConformities\Tables\NonConformitiesTable;
use App\Models\NonConformity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NonConformityResource extends Resource
{
    protected static ?string $model = NonConformity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $navigationLabel = 'Non-conformités';

    protected static ?string $modelLabel = 'non-conformité';

    protected static ?string $pluralModelLabel = 'non-conformités';

    protected static ?string $slug = 'non-conformities';

    public static function form(Schema $schema): Schema
    {
        return NonConformityForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NonConformityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NonConformitiesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // Le tableau et la recherche globale n'utilisent que responsible ;
        // les relations de la fiche détaillée sont chargées par ViewNonConformity.
        $query = parent::getEloquentQuery()->with('responsible');

        $user = auth()->user();

        if ($user !== null && ! $user->canManageNonConformities()) {
            $query->where(function (Builder $query) use ($user): void {
                $query->where('created_by', $user->getKey())
                    ->orWhere('detected_by', $user->getKey())
                    ->orWhereHas('workOrder', fn (Builder $query): Builder => $query->whereIn('status', [
                        WorkOrderStatus::Released->value,
                        WorkOrderStatus::InProgress->value,
                        WorkOrderStatus::QualityCheck->value,
                    ]));
            });
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            CorrectiveActionsRelationManager::class,
            VerificationsRelationManager::class,
            AttachmentsRelationManager::class,
            StatusHistoriesRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'work_order_number', 'description'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var NonConformity $record */
        return [
            'OF' => $record->work_order_number ?? '—',
            'Gravité' => $record->severity->getLabel(),
            'Statut' => $record->status->getLabel(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNonConformities::route('/'),
            'create' => CreateNonConformity::route('/create'),
            'view' => ViewNonConformity::route('/{record}'),
        ];
    }
}
