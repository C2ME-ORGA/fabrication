<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Shipment;
use App\Services\ShipmentService;
use App\Services\ShipmentValidationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editHeader')
                ->label('Modifier l\'en-tête')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema($this->headerForm())
                ->fillForm(fn (Shipment $record): array => $record->only([
                    'planned_ship_at',
                    'carrier_name',
                    'carrier_reference',
                    'delivery_note_reference',
                    'delivery_address',
                    'notes',
                ]))
                ->visible(fn (Shipment $record): bool => (bool) auth()->user()?->can('update', $record))
                ->authorize(fn (Shipment $record): bool => (bool) auth()->user()?->can('update', $record))
                ->action(function (Shipment $record, array $data): void {
                    try {
                        app(ShipmentService::class)->updateHeader($record, $data, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('En-tête mis à jour')
                            ->send();
                    } catch (ValidationException $exception) {
                        $this->notifyRefusal($exception);
                    }
                }),
            Action::make('prepare')
                ->label('Marquer préparée')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('La préparation reste modifiable après réouverture. Aucune quantité n’est réservée et l’OF n’est pas clôturé.')
                ->visible(fn (Shipment $record): bool => (bool) auth()->user()?->can('prepare', $record))
                ->authorize(fn (Shipment $record): bool => (bool) auth()->user()?->can('prepare', $record))
                ->action(function (Shipment $record): void {
                    try {
                        app(ShipmentService::class)->prepare($record, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Expédition préparée')
                            ->send();
                    } catch (ValidationException $exception) {
                        $this->notifyRefusal($exception);
                    }
                }),
            Action::make('validate')
                ->label('Valider le BL')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Valider définitivement l’expédition')
                ->modalDescription('La validation contrôle la qualité, les documents, les quantités et le colisage complet. Elle réserve les quantités et génère le BL et la liste de colisage. L’opération est irréversible hors annulation contrôlée.')
                ->visible(fn (Shipment $record): bool => $record->canBeValidated()
                    && (bool) auth()->user()?->can('validate', $record))
                ->authorize(fn (Shipment $record): bool => (bool) auth()->user()?->can('validate', $record))
                ->action(function (Shipment $record): void {
                    try {
                        $updated = app(ShipmentValidationService::class)->validate($record, auth()->user());
                        $this->record = $updated;

                        Notification::make()
                            ->success()
                            ->title('Expédition validée')
                            ->body('Le bon de livraison et la liste de colisage ont été générés et archivés.')
                            ->send();
                    } catch (ValidationException $exception) {
                        $this->notifyRefusal($exception);
                    }
                }),
            Action::make('ship')
                ->label('Enregistrer le départ')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Enregistrer le départ effectif')
                ->modalDescription('Le départ est distinct de la validation. Les quantités expédiées seront figées à leur valeur validée.')
                ->schema([
                    Textarea::make('comment')
                        ->label('Observation de départ')
                        ->maxLength(10000)
                        ->rows(3),
                ])
                ->visible(fn (Shipment $record): bool => (bool) auth()->user()?->can('ship', $record))
                ->authorize(fn (Shipment $record): bool => (bool) auth()->user()?->can('ship', $record))
                ->action(function (Shipment $record, array $data): void {
                    try {
                        $updated = app(ShipmentService::class)->ship(
                            $record,
                            auth()->user(),
                            $data['comment'] ?? null,
                        );
                        $this->record = $updated;

                        Notification::make()
                            ->success()
                            ->title('Départ enregistré')
                            ->send();
                    } catch (ValidationException $exception) {
                        $this->notifyRefusal($exception);
                    }
                }),
            Action::make('cancel')
                ->label('Annuler l’expédition')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Annuler l’expédition')
                ->modalDescription('Les documents générés sont annulés mais conservés. Une expédition validée libère ses quantités réservées.')
                ->schema([
                    Textarea::make('cancellation_reason')
                        ->label('Motif')
                        ->required()
                        ->minLength(20)
                        ->rows(3),
                ])
                ->visible(fn (Shipment $record): bool => (bool) auth()->user()?->can('cancel', $record))
                ->authorize(fn (Shipment $record): bool => (bool) auth()->user()?->can('cancel', $record))
                ->action(function (Shipment $record, array $data): void {
                    try {
                        $updated = app(ShipmentService::class)->cancel(
                            $record,
                            auth()->user(),
                            (string) $data['cancellation_reason'],
                        );
                        $this->record = $updated;

                        Notification::make()
                            ->success()
                            ->title('Expédition annulée')
                            ->send();
                    } catch (ValidationException $exception) {
                        $this->notifyRefusal($exception);
                    }
                }),
            Action::make('reopen')
                ->label('Rouvrir la préparation')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('L’expédition repasse en brouillon. La réouverture est tracée dans le journal.')
                ->visible(fn (Shipment $record): bool => (bool) auth()->user()?->can('reopen', $record))
                ->authorize(fn (Shipment $record): bool => (bool) auth()->user()?->can('reopen', $record))
                ->action(function (Shipment $record): void {
                    try {
                        app(ShipmentService::class)->reopen($record, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Préparation rouverte')
                            ->send();
                    } catch (ValidationException $exception) {
                        $this->notifyRefusal($exception);
                    }
                }),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function headerForm(): array
    {
        return [
            DateTimePicker::make('planned_ship_at')
                ->label('Date d\'expédition prévue')
                ->seconds(false),
            TextInput::make('carrier_name')
                ->label('Transporteur')
                ->maxLength(150),
            TextInput::make('carrier_reference')
                ->label('Référence transporteur')
                ->maxLength(100),
            TextInput::make('delivery_note_reference')
                ->label('Référence du BL')
                ->maxLength(100),
            TextInput::make('delivery_address')
                ->label('Adresse de livraison')
                ->maxLength(255),
            Textarea::make('notes')
                ->label('Notes')
                ->rows(3),
        ];
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
