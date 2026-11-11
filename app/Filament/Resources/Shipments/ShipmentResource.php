<?php

namespace App\Filament\Resources\Shipments;

use App\Filament\Resources\Shipments\Pages\CreateShipment;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentAuditLogsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentDocumentReviewsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentGeneratedDocumentsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentLinesRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentPackagesRelationManager;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Services\ShipmentQuantityGuard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Joaopaulolndev\FilamentPdfViewer\Infolists\Components\PdfViewerEntry;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Expéditions';

    protected static ?string $modelLabel = 'expédition';

    protected static ?string $pluralModelLabel = 'expéditions';

    protected static ?string $slug = 'shipments';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expédition')
                ->description('Préparation, validation définitive et suivi du départ.')
                ->schema([
                    Select::make('work_order_id')
                        ->label('Ordre de fabrication')
                        ->relationship('workOrder', 'number')
                        ->searchable()
                        ->preload()
                        ->required(),
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
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expédition')
                ->schema([
                    TextEntry::make('number')->label('N° expédition'),
                    TextEntry::make('workOrder.number')->label('Ordre de fabrication'),
                    TextEntry::make('status')->label('État')->badge(),
                    TextEntry::make('planned_ship_at')->label('Prévue le')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('carrier_name')->label('Transporteur')->placeholder('—'),
                    TextEntry::make('carrier_reference')->label('Référence transporteur')->placeholder('—'),
                    TextEntry::make('delivery_note_reference')->label('Référence BL')->placeholder('—'),
                    TextEntry::make('delivery_address')->label('Adresse de livraison')->placeholder('—'),
                    TextEntry::make('preparer.name')->label('Préparée par')->placeholder('—'),
                    TextEntry::make('prepared_at')->label('Préparée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('validator.name')->label('Validée par')->placeholder('—'),
                    TextEntry::make('validated_at')->label('Validée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('shipper.name')->label('Départ enregistré par')->placeholder('—'),
                    TextEntry::make('actual_ship_at')->label('Départ effectif')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('canceller.name')->label('Annulée par')->placeholder('—'),
                    TextEntry::make('cancelled_at')->label('Annulée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('cancellation_reason')->label('Motif d’annulation')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Bon de livraison')
                ->description('Document généré à la validation. Le fichier est servi par une route authentifiée et autorisée.')
                ->schema([
                    PdfViewerEntry::make('delivery_note_pdf')
                        ->label('BL archivé')
                        ->minHeight('60svh')
                        ->fileUrl(fn (Shipment $record): ?string => ($document = $record->latestDeliveryNote())
                            ? route('generated-documents.view', $document)
                            : null)
                        ->visible(fn (Shipment $record): bool => $record->latestDeliveryNote() !== null),
                    Html::make(function (Shipment $record): string {
                        $document = $record->latestDeliveryNote();

                        if ($document === null) {
                            return '<span class="text-sm text-gray-500">Aucun bon de livraison archivé.</span>';
                        }

                        $viewUrl = e(route('generated-documents.view', $document));
                        $downloadUrl = e(route('generated-documents.download', $document));

                        return '<div class="flex flex-wrap items-center gap-4 text-sm">'
                            .'<span>BL : <strong>'.e($document->reference).'</strong></span>'
                            .'<a class="text-primary-600 underline" target="_blank" rel="noopener" href="'.$viewUrl.'">Ouvrir</a>'
                            .'<a class="text-primary-600 underline" href="'.$downloadUrl.'">Télécharger</a>'
                            .'</div>';
                    }),
                ]),
            Section::make('Quantités par repère')
                ->description('La préparation ne réserve aucune quantité ; la validation réserve les quantités préparées.')
                ->schema([
                    Html::make(function (Shipment $record): string {
                        $guard = app(ShipmentQuantityGuard::class);
                        $rows = $record->lines()
                            ->with('workOrderItem')
                            ->get()
                            ->map(function (ShipmentLine $line) use ($guard): string {
                                $item = $line->workOrderItem;
                                $accepted = $item === null ? 0 : $guard->acceptedQuantity($item);
                                $remainingToPrepare = $item === null ? 0 : $guard->remainingToPrepareForItem($item);

                                return '<tr>'
                                    .'<td>'.e($line->mark_snapshot).'</td>'
                                    .'<td>'.e($line->designation_snapshot).'</td>'
                                    .'<td class="text-right">'.$accepted.'</td>'
                                    .'<td class="text-right">'.$line->quantity_prepared.'</td>'
                                    .'<td class="text-right">'.$line->packagedQuantity().'</td>'
                                    .'<td class="text-right">'.$line->remainingToPackage().'</td>'
                                    .'<td class="text-right">'.$remainingToPrepare.'</td>'
                                    .'</tr>';
                            })
                            ->implode('');

                        if ($rows === '') {
                            return '<span class="text-sm text-gray-500">Aucune ligne de préparation.</span>';
                        }

                        return '<table class="w-full text-sm">'
                            .'<thead><tr>'
                            .'<th class="text-left">Repère</th>'
                            .'<th class="text-left">Désignation</th>'
                            .'<th class="text-right">Accepté OF</th>'
                            .'<th class="text-right">Préparé</th>'
                            .'<th class="text-right">Colisé</th>'
                            .'<th class="text-right">Reste à coliser</th>'
                            .'<th class="text-right">Reste à préparer sur l’OF</th>'
                            .'</tr></thead><tbody>'.$rows.'</tbody></table>';
                    }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('N° expédition')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('workOrder.number')
                    ->label('OF')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('État')
                    ->badge(),
                TextColumn::make('planned_ship_at')
                    ->label('Prévue le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lignes'),
                TextColumn::make('packages_count')
                    ->counts('packages')
                    ->label('Colis'),
                TextColumn::make('preparer.name')
                    ->label('Préparée par')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Consulter')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Shipment $record): string => static::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ShipmentLinesRelationManager::class,
            ShipmentPackagesRelationManager::class,
            ShipmentDocumentReviewsRelationManager::class,
            ShipmentGeneratedDocumentsRelationManager::class,
            ShipmentAuditLogsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workOrder', 'preparer', 'validator', 'shipper'])
            ->withCount(['lines', 'packages']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'create' => CreateShipment::route('/create'),
            'view' => ViewShipment::route('/{record}'),
        ];
    }
}
