<?php

namespace App\Filament\Workshop\Resources\QualityControls\RelationManagers;

use App\Models\QualityControlResult;
use App\Models\WorkOrderQualityControl;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    protected static ?string $title = 'Résultats et validations';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof WorkOrderQualityControl
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['point', 'item', 'performer', 'validations.validator', 'attachments']))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('created_at')
                    ->label('Saisi le')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('point.label')
                    ->label('Point')
                    ->wrap(),
                TextColumn::make('item.mark')
                    ->label('Repère')
                    ->placeholder('—'),
                TextColumn::make('value')
                    ->label('Valeur')
                    ->state(fn (QualityControlResult $record): string => $record->displayValue()),
                IconColumn::make('is_conform')
                    ->label('Conforme')
                    ->boolean()
                    ->placeholder('—'),
                TextColumn::make('performer.name')
                    ->label('Contrôleur')
                    ->placeholder('—'),
                TextColumn::make('validations_count')
                    ->label('Validations')
                    ->state(fn (QualityControlResult $record): string => $record->validatedUsersCount().' / '.$record->point->requiredValidations())
                    ->badge(),
                TextColumn::make('validations_summary')
                    ->label('Décisions')
                    ->state(fn (QualityControlResult $record): string => $record->validations
                        ->map(fn ($validation): string => $validation->decision->getLabel().' — '.($validation->validator?->name ?? '—'))
                        ->implode(' · '))
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([])
            ->recordActions([
                Action::make('viewResultEvidence')
                    ->label('Preuves')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->modalHeading('Preuves du résultat')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalContent(fn (QualityControlResult $record) => view(
                        'filament.quality.result-attachments',
                        ['attachments' => $record->attachments],
                    ))
                    ->visible(fn (QualityControlResult $record): bool => $record->attachments->isNotEmpty()),
            ])
            ->toolbarActions([]);
    }
}
