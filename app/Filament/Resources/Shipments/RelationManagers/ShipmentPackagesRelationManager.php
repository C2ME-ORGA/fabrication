<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Enums\ShipmentPackageType;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentPackage;
use App\Models\ShipmentPackageLine;
use App\Services\ShipmentPackagingService;
use App\Services\ShipmentQuantityGuard;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ShipmentPackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Colis et répartition';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Shipment
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Colis')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('package_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('label')
                    ->label('Libellé')
                    ->placeholder('—'),
                TextColumn::make('position')
                    ->label('Position')
                    ->numeric(decimalPlaces: 0),
                TextColumn::make('contents')
                    ->label('Contenu')
                    ->state(fn (ShipmentPackage $record): string => $record->contentSummary())
                    ->wrap(),
                TextColumn::make('quantity')
                    ->label('Quantité colisée')
                    ->state(fn (ShipmentPackage $record): int => (int) $record->packageLines()->sum('quantity')),
            ])
            ->headerActions([
                Action::make('addPackage')
                    ->label('Ajouter un colis')
                    ->icon(Heroicon::OutlinedCube)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->placeholder('Généré automatiquement si vide')
                            ->maxLength(50),
                        Select::make('package_type')
                            ->label('Type')
                            ->options($this->packageTypeOptions())
                            ->default(ShipmentPackageType::Pallet->value)
                            ->required(),
                        TextInput::make('label')
                            ->label('Libellé')
                            ->maxLength(150),
                        TextInput::make('weight_kg')
                            ->label('Poids (kg)')
                            ->numeric(),
                        TextInput::make('length_cm')
                            ->label('Longueur (cm)')
                            ->numeric(),
                        TextInput::make('width_cm')
                            ->label('Largeur (cm)')
                            ->numeric(),
                        TextInput::make('height_cm')
                            ->label('Hauteur (cm)')
                            ->numeric(),
                        TextInput::make('seal_number')
                            ->label('Numéro de plomb')
                            ->maxLength(100),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
                    ->action(function (array $data): void {
                        try {
                            app(ShipmentPackagingService::class)
                                ->addPackage($this->getOwnerRecord(), $data, auth()->user());

                            Notification::make()->success()->title('Colis ajouté')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Répartir')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->schema([
                        Select::make('shipment_line_id')
                            ->label('Ligne de préparation')
                            ->options(fn (ShipmentPackage $record): array => $this->lineOptions($record))
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Quantité dans ce colis')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->visible(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->authorize(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->action(function (ShipmentPackage $record, array $data): void {
                        try {
                            $line = ShipmentLine::query()->findOrFail($data['shipment_line_id']);

                            app(ShipmentPackagingService::class)->assign(
                                $record,
                                $line,
                                (int) $data['quantity'],
                                auth()->user(),
                            );

                            Notification::make()->success()->title('Répartition enregistrée')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('removeAllocation')
                    ->label('Retirer une répartition')
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('warning')
                    ->schema([
                        Select::make('allocation_id')
                            ->label('Répartition')
                            ->options(fn (ShipmentPackage $record): array => $this->allocationOptions($record))
                            ->required(),
                    ])
                    ->visible(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->authorize(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->action(function (ShipmentPackage $record, array $data): void {
                        try {
                            $allocation = ShipmentPackageLine::query()
                                ->where('shipment_package_id', $record->getKey())
                                ->findOrFail($data['allocation_id']);

                            app(ShipmentPackagingService::class)
                                ->removeAllocation($allocation, auth()->user());

                            Notification::make()->success()->title('Répartition retirée')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('editPackage')
                    ->label('Modifier')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(50),
                        Select::make('package_type')
                            ->label('Type')
                            ->options($this->packageTypeOptions())
                            ->required(),
                        TextInput::make('label')
                            ->label('Libellé')
                            ->maxLength(150),
                        TextInput::make('seal_number')
                            ->label('Numéro de plomb')
                            ->maxLength(100),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2),
                    ])
                    ->fillForm(fn (ShipmentPackage $record): array => $record->only([
                        'code',
                        'package_type',
                        'label',
                        'seal_number',
                        'notes',
                    ]))
                    ->visible(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->authorize(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->action(function (ShipmentPackage $record, array $data): void {
                        try {
                            app(ShipmentPackagingService::class)
                                ->updatePackage($record, $data, auth()->user());

                            Notification::make()->success()->title('Colis mis à jour')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('removePackage')
                    ->label('Retirer')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->authorize(fn (ShipmentPackage $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->action(function (ShipmentPackage $record): void {
                        try {
                            app(ShipmentPackagingService::class)
                                ->removePackage($record, auth()->user());

                            Notification::make()->success()->title('Colis retiré')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected function lineOptions(ShipmentPackage $package): array
    {
        $guard = app(ShipmentQuantityGuard::class);

        // Le colis en cours est exclu du calcul : sa répartition actuelle
        // sera remplacée, elle ne doit donc pas masquer la ligne. Une ligne
        // entièrement colisée dans d'autres colis n'est plus proposée.
        return $guard->packageableLines($package->shipment, $package)
            ->mapWithKeys(function (ShipmentLine $line) use ($guard, $package): array {
                $available = $guard->remainingToPackageForLine($line, $package);
                $current = $package->packageLines()
                    ->where('shipment_line_id', $line->getKey())
                    ->value('quantity');

                $label = "{$line->mark_snapshot} — préparé {$line->quantity_prepared}, disponible {$available}";

                if ($current !== null) {
                    $label .= " (dans ce colis : {$current})";
                }

                return [$line->getKey() => $label];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function allocationOptions(ShipmentPackage $package): array
    {
        return $package->packageLines()
            ->with('shipmentLine')
            ->get()
            ->mapWithKeys(fn (ShipmentPackageLine $allocation): array => [
                $allocation->getKey() => $allocation->shipmentLine->mark_snapshot.' × '.$allocation->quantity,
            ])
            ->all();
    }

    protected function notifyRefusal(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action refusée')
            ->body((string) collect($exception->errors())->flatten()->first())
            ->send();
    }

    /**
     * @return array<string, string>
     */
    protected function packageTypeOptions(): array
    {
        return collect(ShipmentPackageType::cases())
            ->mapWithKeys(fn (ShipmentPackageType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }
}
