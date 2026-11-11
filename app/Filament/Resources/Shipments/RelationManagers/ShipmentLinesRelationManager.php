<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\WorkOrderItem;
use App\Services\ShipmentService;
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

class ShipmentLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lignes de préparation';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Shipment
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mark_snapshot')
            ->columns([
                TextColumn::make('mark_snapshot')
                    ->label('Repère')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('designation_snapshot')
                    ->label('Désignation')
                    ->limit(45)
                    ->wrap(),
                TextColumn::make('quantity_prepared')
                    ->label('Préparé')
                    ->numeric(decimalPlaces: 0),
                TextColumn::make('packaged')
                    ->label('Colisé')
                    ->state(fn (ShipmentLine $record): int => $record->packagedQuantity()),
                TextColumn::make('remaining')
                    ->label('Reste à coliser')
                    ->state(fn (ShipmentLine $record): int => $record->remainingToPackage()),
                TextColumn::make('quantity_shipped')
                    ->label('Expédié')
                    ->numeric(decimalPlaces: 0),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('addLine')
                    ->label('Ajouter une ligne')
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        Select::make('work_order_item_id')
                            ->label('Repère')
                            ->options(fn (): array => $this->itemOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity_prepared')
                            ->label('Quantité préparée')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
                    ->action(function (array $data): void {
                        try {
                            $item = WorkOrderItem::query()->findOrFail($data['work_order_item_id']);

                            app(ShipmentService::class)->addLine(
                                $this->getOwnerRecord(),
                                $item,
                                (int) $data['quantity_prepared'],
                                auth()->user(),
                                ['notes' => $data['notes'] ?? null],
                            );

                            Notification::make()->success()->title('Ligne ajoutée')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('editLine')
                    ->label('Modifier')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        TextInput::make('quantity_prepared')
                            ->label('Quantité préparée')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2),
                    ])
                    ->fillForm(fn (ShipmentLine $record): array => [
                        'quantity_prepared' => $record->quantity_prepared,
                        'notes' => $record->notes,
                    ])
                    ->visible(fn (ShipmentLine $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->authorize(fn (ShipmentLine $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->action(function (ShipmentLine $record, array $data): void {
                        try {
                            app(ShipmentService::class)->updateLine(
                                $record,
                                (int) $data['quantity_prepared'],
                                auth()->user(),
                                ['notes' => $data['notes'] ?? null],
                            );

                            Notification::make()->success()->title('Ligne mise à jour')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
                Action::make('removeLine')
                    ->label('Retirer')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ShipmentLine $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->authorize(fn (ShipmentLine $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->action(function (ShipmentLine $record): void {
                        try {
                            app(ShipmentService::class)->removeLine($record, auth()->user());

                            Notification::make()->success()->title('Ligne retirée')->send();
                        } catch (ValidationException $exception) {
                            $this->notifyRefusal($exception);
                        }
                    }),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected function itemOptions(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->getOwnerRecord();

        $alreadyUsed = $shipment->lines()->pluck('work_order_item_id')->all();

        return WorkOrderItem::query()
            ->where('work_order_id', $shipment->work_order_id)
            ->whereNotIn('id', $alreadyUsed)
            ->orderBy('mark')
            ->pluck('mark', 'id')
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
}
