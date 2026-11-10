<?php

namespace App\Filament\Resources\WorkshopRoutings\RelationManagers;

use App\Enums\QualityRequirementScope;
use App\Enums\QualityRequirementTrigger;
use App\Models\QualityControlTemplate;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use App\Services\WorkshopRoutingQualityRequirementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Exigences qualité portées par les opérations d'une gamme.
 *
 * La gamme ne fait que référencer l'identité d'un modèle : la version active
 * est résolue et copiée au moment de la génération du plan qualité de l'OF.
 */
class QualityRequirementsRelationManager extends RelationManager
{
    protected static string $relationship = 'qualityRequirements';

    protected static ?string $title = 'Exigences qualité';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof WorkshopRouting
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('position')
                    ->label('Ordre')
                    ->numeric()
                    ->width('70px'),
                TextColumn::make('operation.code')
                    ->label('Opération')
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('template.code')
                    ->label('Modèle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope')
                    ->label('Portée')
                    ->badge(),
                TextColumn::make('trigger')
                    ->label('Déclencheur')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_mandatory')
                    ->label('Obligatoire')
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('createRequirement')
                    ->label('Ajouter une exigence')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (): bool => $this->canManage())
                    ->authorize(fn (): bool => $this->canManage())
                    ->schema($this->requirementFormSchema(creating: true))
                    ->modalHeading('Ajouter une exigence qualité')
                    ->modalSubmitActionLabel('Ajouter')
                    ->action(function (array $data): void {
                        try {
                            $operation = $this->routing()->operations()
                                ->whereKey((int) $data['workshop_routing_operation_id'])
                                ->first();

                            if ($operation === null) {
                                $this->notifyRefusal(ValidationException::withMessages([
                                    'workshop_routing_operation_id' => 'Cette opération n\'appartient pas à la gamme.',
                                ]));

                                return;
                            }

                            $requirement = app(WorkshopRoutingQualityRequirementService::class)
                                ->create($operation, $data, auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Exigence ajoutée')
                                ->body("Le modèle {$requirement->template->code} est désormais exigé.")
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('editRequirement')
                    ->label('Modifier')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => $this->canManage())
                    ->authorize(fn (): bool => $this->canManage())
                    ->schema($this->requirementFormSchema(creating: false))
                    ->fillForm(fn (WorkshopRoutingOperationQualityRequirement $record): array => [
                        'workshop_routing_operation_id' => $record->workshop_routing_operation_id,
                        'quality_control_template_id' => $record->quality_control_template_id,
                        'scope' => $record->scope->value,
                        'trigger' => $record->trigger->value,
                        'is_mandatory' => $record->is_mandatory,
                        'position' => $record->position,
                    ])
                    ->modalHeading('Modifier l\'exigence qualité')
                    ->action(function (WorkshopRoutingOperationQualityRequirement $record, array $data): void {
                        try {
                            app(WorkshopRoutingQualityRequirementService::class)
                                ->update($record, $data, auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Exigence mise à jour')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('deleteRequirement')
                    ->label('Supprimer')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canManage())
                    ->authorize(fn (): bool => $this->canManage())
                    ->action(function (WorkshopRoutingOperationQualityRequirement $record): void {
                        try {
                            app(WorkshopRoutingQualityRequirementService::class)
                                ->delete($record, auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Exigence supprimée')
                                ->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function requirementFormSchema(bool $creating): array
    {
        return [
            Select::make('workshop_routing_operation_id')
                ->label('Opération de gamme')
                ->options(fn (): array => $this->routing()->operations()
                    ->pluck('code', 'id')
                    ->all())
                ->required()
                ->disabled(! $creating)
                ->dehydrated(),
            Select::make('quality_control_template_id')
                ->label('Modèle de contrôle')
                ->options(fn (): array => QualityControlTemplate::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->pluck('code', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->native(false),
            Select::make('scope')
                ->label('Portée')
                ->options(QualityRequirementScope::options())
                ->default(QualityRequirementScope::Operation->value)
                ->required()
                ->native(false)
                ->live()
                ->helperText('L\'échantillonnage par fréquence n\'est possible que sur la portée « Repère / pièce ».'),
            Select::make('trigger')
                ->label('Déclencheur')
                ->options(QualityRequirementTrigger::options())
                ->default(QualityRequirementTrigger::OnCompletion->value)
                ->required()
                ->native(false),
            TextInput::make('position')
                ->label('Ordre')
                ->numeric()
                ->minValue(1)
                ->placeholder('Automatique'),
            Toggle::make('is_mandatory')
                ->label('Exigence obligatoire')
                ->default(true),
        ];
    }

    protected function routing(): WorkshopRouting
    {
        /** @var WorkshopRouting $routing */
        $routing = $this->getOwnerRecord();

        return $routing;
    }

    protected function canManage(): bool
    {
        return (bool) auth()->user()?->can('manageQualityRequirements', $this->routing());
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }
}
