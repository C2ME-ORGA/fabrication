<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Enums\WorkOrderItemStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderBomImportService;
use App\Services\WorkOrderItemService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Pièces et repères';

    /**
     * Le récapitulatif et la table sont rendus avec la fiche OF : la
     * nomenclature est paginée et doit être visible immédiatement.
     */
    protected static ?bool $isLazyLoaded = false;

    /**
     * Aperçu de l'import en cours. Il ne constitue jamais une source de
     * vérité : le serveur relit et revalide le fichier à la confirmation.
     *
     * @var array<string, mixed>|null
     */
    public ?array $bomPreview = null;

    public ?string $bomImportError = null;

    /**
     * Seuls les administrateurs ayant accès à l'OF peuvent ouvrir la gestion
     * des pièces. Les actions sont ensuite contrôlées individuellement.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mark')
            ->defaultSort('mark')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->header(fn (): View => view('filament.work-orders.items-summary', [
                'summary' => app(WorkOrderItemService::class)->summary($this->getOwnerRecord()),
            ]))
            ->columns([
                TextColumn::make('mark')
                    ->label('Repère')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('designation')
                    ->label('Désignation')
                    ->searchable()
                    ->limit(45)
                    ->wrap(),
                TextColumn::make('profile')
                    ->label('Profil')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('steel_grade')
                    ->label('Nuance')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('length_mm')
                    ->label('Longueur')
                    ->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->suffix(' mm')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('quantity_planned')
                    ->label('Prévu')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->sortable(),
                TextColumn::make('quantity_produced')
                    ->label('Fabriqué')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->sortable(),
                TextColumn::make('accepted_quantity')
                    ->label('Accepté')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' '),
                TextColumn::make('quantity_rejected')
                    ->label('Rebuté')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->sortable(),
                TextColumn::make('remaining_quantity')
                    ->label('Restant')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: ' '),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->sortable(),
                TextColumn::make('operation_group')
                    ->label('Groupe d’opérations')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit_weight_kg')
                    ->label('Poids unitaire')
                    ->numeric(decimalPlaces: 3, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->suffix(' kg')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('total_weight_kg')
                    ->label('Poids total')
                    ->numeric(decimalPlaces: 3, decimalSeparator: ',', thousandsSeparator: ' ')
                    ->suffix(' kg')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('État')
                    ->options(WorkOrderItemStatus::options()),
                SelectFilter::make('operation_group')
                    ->label('Groupe d’opérations')
                    ->options(fn (): array => app(WorkOrderItemService::class)
                        ->operationGroups($this->getOwnerRecord()))
                    ->searchable(),
            ])
            ->headerActions([
                Action::make('createItem')
                    ->label('Ajouter une pièce')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->modalHeading('Ajouter une pièce')
                    ->modalSubmitActionLabel('Ajouter')
                    ->schema($this->itemFormSchema())
                    ->visible(fn (): bool => $this->canManageItems())
                    ->authorize(fn (): bool => $this->canManageItems())
                    ->action(function (array $data): void {
                        $this->assertCan('manageItems', $this->getOwnerRecord());

                        try {
                            $item = app(WorkOrderItemService::class)->create(
                                $this->getOwnerRecord(),
                                $data,
                                auth()->user(),
                            );
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($item->wasRecentlyCreated ? 'Pièce ajoutée' : 'Repère fusionné')
                            ->body($item->wasRecentlyCreated
                                ? "La pièce {$item->mark} a été ajoutée."
                                : "Le repère {$item->mark} existait déjà : les quantités prévues ont été additionnées à la pièce existante.")
                            ->send();
                    }),
                Action::make('importBom')
                    ->label('Importer une nomenclature')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->color('info')
                    ->modalHeading('Importer une nomenclature CSV')
                    ->modalDescription('Le fichier est analysé et validé avant toute écriture. La confirmation relance une validation complète côté serveur.')
                    ->modalSubmitActionLabel('Confirmer l’import')
                    ->modalWidth('7xl')
                    ->mountUsing(function (): void {
                        $this->resetBomPreview();
                    })
                    ->modalSubmitAction(fn (Action $action): Action => $action->disabled(
                        $this->bomPreview === null || ! ($this->bomPreview['valid'] ?? false),
                    ))
                    ->schema([
                        FileUpload::make('file')
                            ->label('Fichier CSV')
                            ->required()
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/csv',
                                'application/vnd.ms-excel',
                            ])
                            ->maxSize((int) config('bom.max_size_kb', 5120))
                            ->helperText('UTF-8, 5 Mo maximum. Colonnes obligatoires : mark, designation, quantity_planned.')
                            ->live()
                            ->afterStateUpdated(function (mixed $state): void {
                                $file = is_array($state) ? Arr::first($state) : $state;

                                $this->previewBom($file);
                            }),
                        ViewField::make('bom_preview')
                            ->view('filament.work-orders.bom-import-preview', fn (): array => [
                                'preview' => $this->bomPreview,
                                'error' => $this->bomImportError,
                            ])
                            ->visible(fn (): bool => $this->bomPreview !== null || $this->bomImportError !== null),
                    ])
                    ->visible(fn (): bool => $this->canManageItems())
                    ->authorize(fn (): bool => $this->canManageItems())
                    ->action(fn (array $data) => $this->confirmBomImport($data['file'] ?? null)),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema($this->itemFormSchema())
                    ->visible(fn (WorkOrderItem $record): bool => $this->canOnItem('update', $record))
                    ->authorize(fn (WorkOrderItem $record): bool => $this->canOnItem('update', $record))
                    ->successNotification(null)
                    ->using(function (WorkOrderItem $record, array $data): void {
                        $this->assertCan('update', $record);

                        try {
                            $updated = app(WorkOrderItemService::class)->update(
                                $record,
                                $data,
                                auth()->user(),
                            );
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);

                            return;
                        }

                        if ($updated->getKey() !== $record->getKey()) {
                            Notification::make()
                                ->success()
                                ->title('Repère fusionné')
                                ->body("Le repère a été fusionné dans {$updated->mark}. Les quantités prévues ont été additionnées.")
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Pièce modifiée')
                            ->body("La pièce {$updated->mark} a été mise à jour.")
                            ->send();
                    }),
                Action::make('recordProduction')
                    ->label('Saisir la production')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->color('primary')
                    ->modalHeading('Saisir la production')
                    ->modalDescription('La quantité fabriquée inclut les rebuts. La quantité rebutée ne peut pas dépasser la quantité fabriquée.')
                    ->schema([
                        TextInput::make('quantity_produced')
                            ->label('Quantité totale fabriquée')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Nombre total de pièces produites, rebuts compris.'),
                        TextInput::make('quantity_rejected')
                            ->label('Quantité totale rebutée')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Doit être inférieure ou égale à la quantité fabriquée.'),
                    ])
                    ->fillForm(fn (WorkOrderItem $record): array => [
                        'quantity_produced' => $record->quantity_produced,
                        'quantity_rejected' => $record->quantity_rejected,
                    ])
                    ->visible(fn (WorkOrderItem $record): bool => $this->canOnItem('recordProduction', $record))
                    ->authorize(fn (WorkOrderItem $record): bool => $this->canOnItem('recordProduction', $record))
                    ->action(fn (WorkOrderItem $record, array $data) => $this->recordProductionForItem(
                        $record,
                        (int) $data['quantity_produced'],
                        (int) $data['quantity_rejected'],
                    )),
                Action::make('start')
                    ->label('Démarrer')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Démarrer la fabrication')
                    ->visible(fn (WorkOrderItem $record): bool => $record->status === WorkOrderItemStatus::Todo
                        && $this->canOnItem('changeStatus', $record))
                    ->authorize(fn (WorkOrderItem $record): bool => $this->canOnItem('changeStatus', $record))
                    ->action(fn (WorkOrderItem $record) => $this->changeStatus($record, 'start', 'Pièce démarrée')),
                Action::make('close')
                    ->label('Clôturer')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Clôturer la pièce')
                    ->modalDescription('La pièce doit être entièrement fabriquée. Cette action est définitive.')
                    ->visible(fn (WorkOrderItem $record): bool => $record->status === WorkOrderItemStatus::Produced
                        && $this->canOnItem('changeStatus', $record))
                    ->authorize(fn (WorkOrderItem $record): bool => $this->canOnItem('changeStatus', $record))
                    ->action(fn (WorkOrderItem $record) => $this->changeStatus($record, 'close', 'Pièce clôturée')),
                Action::make('cancel')
                    ->label('Annuler')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Annuler la pièce')
                    ->modalDescription('La production déjà enregistrée reste conservée dans l’historique. Cette action est définitive.')
                    ->visible(fn (WorkOrderItem $record): bool => ! $record->status->isFinal()
                        && $this->canOnItem('changeStatus', $record))
                    ->authorize(fn (WorkOrderItem $record): bool => $this->canOnItem('changeStatus', $record))
                    ->action(fn (WorkOrderItem $record) => $this->changeStatus($record, 'cancel', 'Pièce annulée')),
                DeleteAction::make()
                    ->visible(fn (WorkOrderItem $record): bool => $this->canOnItem('delete', $record))
                    ->authorize(fn (WorkOrderItem $record): bool => $this->canOnItem('delete', $record))
                    ->successNotification(null)
                    ->failureNotification(null)
                    ->using(function (WorkOrderItem $record): bool {
                        $this->assertCan('delete', $record);

                        try {
                            app(WorkOrderItemService::class)->delete($record, auth()->user());
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);

                            return false;
                        }

                        Notification::make()
                            ->success()
                            ->title('Pièce supprimée')
                            ->body("La pièce {$record->mark} a été supprimée.")
                            ->send();

                        return true;
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * Formulaire commun de création et de modification. Les champs calculés
     * et les champs de production ne sont volontairement pas exposés.
     *
     * @return array<int, mixed>
     */
    protected function itemFormSchema(): array
    {
        return [
            Section::make('Pièce')
                ->columns(2)
                ->schema([
                    TextInput::make('mark')
                        ->label('Repère')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('designation')
                        ->label('Désignation')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('profile')
                        ->label('Profil')
                        ->maxLength(100),
                    TextInput::make('steel_grade')
                        ->label('Nuance d’acier')
                        ->maxLength(100),
                    TextInput::make('length_mm')
                        ->label('Longueur')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->suffix(' mm'),
                    TextInput::make('quantity_planned')
                        ->label('Quantité prévue')
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->default(1),
                    TextInput::make('unit_weight_kg')
                        ->label('Poids unitaire')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->suffix(' kg'),
                    TextInput::make('operation_group')
                        ->label('Groupe d’opérations')
                        ->maxLength(100),
                    Textarea::make('notes')
                        ->label('Observations')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * Analyse un fichier téléversé et met à jour l'aperçu. Cette méthode est
     * publique car elle est appelée par le composant Livewire après le
     * téléversement ; le service vérifie l'autorisation et n'écrit rien.
     */
    public function previewBom(mixed $file): void
    {
        $this->resetBomPreview();

        if (! $file instanceof UploadedFile) {
            return;
        }

        $user = auth()->user();

        abort_if($user === null, 403);

        try {
            $preview = app(WorkOrderBomImportService::class)->preview(
                $this->ownerWorkOrder(),
                $file,
                $user,
            );

            $this->bomPreview = $preview->toArray();
        } catch (ValidationException $exception) {
            $this->bomImportError = (string) collect($exception->errors())->flatten()->first();
        }
    }

    /**
     * Confirmation serveur de l'import. Le fichier est relu et revalidé par
     * le service, sans jamais faire confiance à l'aperçu Livewire.
     */
    public function confirmBomImport(mixed $file): void
    {
        $this->assertCan('manageItems', $this->ownerWorkOrder());

        if (is_array($file)) {
            $file = Arr::first($file);
        }

        if (! $file instanceof UploadedFile) {
            Notification::make()
                ->danger()
                ->title('Fichier manquant')
                ->body('Sélectionnez un fichier CSV avant de confirmer.')
                ->send();

            return;
        }

        $user = auth()->user();

        abort_if($user === null, 403);

        try {
            $import = app(WorkOrderBomImportService::class)->import(
                $this->ownerWorkOrder(),
                $file,
                $user,
            );
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);

            return;
        }

        $this->resetBomPreview();
        $this->resetTable();

        Notification::make()
            ->success()
            ->title('Nomenclature importée')
            ->body(
                "{$import->rows_total} ligne(s), {$import->rows_valid} valide(s). "
                ."Fichier {$import->original_filename}."
            )
            ->send();
    }

    protected function resetBomPreview(): void
    {
        $this->bomPreview = null;
        $this->bomImportError = null;
    }

    protected function ownerWorkOrder(): WorkOrder
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $this->getOwnerRecord();

        return $workOrder;
    }

    /**
     * Point d'entrée serveur de l'action de production. Il est public pour
     * rester testable comme une action Livewire directe, et vérifie donc
     * lui-même l'autorisation avant d'appeler le service.
     */
    public function recordProductionForItem(WorkOrderItem $item, int $quantityProduced, int $quantityRejected): void
    {
        $this->assertCan('recordProduction', $item);

        try {
            $item = app(WorkOrderItemService::class)->recordProduction(
                $item,
                $quantityProduced,
                $quantityRejected,
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);

            return;
        }

        Notification::make()
            ->success()
            ->title('Production enregistrée')
            ->body(
                "Fabriqué : {$item->quantity_produced} · Accepté : {$item->accepted_quantity} · "
                ."Rebuté : {$item->quantity_rejected} · Restant : {$item->remaining_quantity} · "
                ."État : {$item->status->getLabel()}"
            )
            ->send();
    }

    protected function canManageItems(): bool
    {
        return (bool) auth()->user()?->can('manageItems', $this->getOwnerRecord());
    }

    protected function canOnItem(string $ability, WorkOrderItem $item): bool
    {
        return (bool) auth()->user()?->can($ability, $item);
    }

    protected function assertCan(string $ability, mixed $arguments): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        Gate::forUser($user)->authorize($ability, $arguments);
    }

    protected function changeStatus(WorkOrderItem $record, string $operation, string $label): void
    {
        $this->assertCan('changeStatus', $record);

        try {
            $item = app(WorkOrderItemService::class)->{$operation}($record, auth()->user());
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);

            return;
        }

        Notification::make()
            ->success()
            ->title($label)
            ->body("État actuel : {$item->status->getLabel()}.")
            ->send();
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
