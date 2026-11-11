<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\DTO\ShipmentDocumentReviewRequirement;
use App\Enums\ShipmentDocumentReviewDecision;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Shipment;
use App\Services\ShipmentDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ShipmentDocumentReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'documentReviews';

    protected static ?string $title = 'Revue documentaire P7';

    protected static ?bool $isLazyLoaded = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Shipment
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('decided_at', 'desc')
            ->header(fn (): View => view('filament.shipments.document-review-summary', [
                'requirements' => $this->requirements(),
            ]))
            ->columns([
                TextColumn::make('document.reference')
                    ->label('Document')
                    ->placeholder('—')
                    ->weight('bold'),
                TextColumn::make('document.title')
                    ->label('Titre')
                    ->limit(35)
                    ->wrap(),
                TextColumn::make('version.revision')
                    ->label('Révision')
                    ->placeholder('—'),
                TextColumn::make('version.version_number')
                    ->label('Version')
                    ->numeric(decimalPlaces: 0),
                TextColumn::make('decision')
                    ->label('Décision')
                    ->badge(),
                TextColumn::make('comment')
                    ->label('Commentaire')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('reviewer.name')
                    ->label('Décideur')
                    ->placeholder('—'),
                TextColumn::make('decided_at')
                    ->label('Décidé le')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('decideDocumentReview')
                    ->label('Décider P7')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->schema([
                        Select::make('requirement')
                            ->label('Révision à examiner')
                            ->options(fn (): array => $this->requirementOptions())
                            ->required(),
                        Select::make('decision')
                            ->label('Décision')
                            ->options(collect(ShipmentDocumentReviewDecision::cases())
                                ->mapWithKeys(fn (ShipmentDocumentReviewDecision $decision): array => [
                                    $decision->value => $decision->getLabel(),
                                ])
                                ->all())
                            ->required(),
                        Textarea::make('comment')
                            ->label('Commentaire obligatoire')
                            ->required()
                            ->minLength(3)
                            ->maxLength(10000)
                            ->rows(4),
                    ])
                    ->visible(fn (): bool => $this->canDecide())
                    ->authorize(fn (): bool => $this->canDecide())
                    ->action(function (array $data): void {
                        try {
                            [$documentId, $versionId] = array_map('intval', explode(':', (string) $data['requirement']));

                            $document = Document::query()->findOrFail($documentId);
                            $version = DocumentVersion::query()->findOrFail($versionId);

                            app(ShipmentDocumentService::class)->decide(
                                $this->getOwnerRecord()->workOrder,
                                $document,
                                $version,
                                ShipmentDocumentReviewDecision::from((string) $data['decision']),
                                auth()->user(),
                                (string) $data['comment'],
                                $this->getOwnerRecord(),
                            );

                            Notification::make()
                                ->success()
                                ->title('Décision documentaire enregistrée')
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Décision refusée')
                                ->body((string) collect($exception->errors())->flatten()->first())
                                ->send();
                        }
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * @return Collection<int, ShipmentDocumentReviewRequirement>
     */
    protected function requirements(): Collection
    {
        return app(ShipmentDocumentService::class)
            ->requirements($this->getOwnerRecord()->workOrder);
    }

    /**
     * @return array<string, string>
     */
    protected function requirementOptions(): array
    {
        return $this->requirements()
            ->filter(fn (ShipmentDocumentReviewRequirement $requirement): bool => $requirement->isPending() || $requirement->isBlocked())
            ->mapWithKeys(fn (ShipmentDocumentReviewRequirement $requirement): array => [
                $requirement->document->getKey().':'.$requirement->version->getKey() => $requirement->reference()
                        .' — v'.($requirement->version->version_number ?? '?')
                        .' ('.($requirement->version->revision ?? '?').')'
                        .' — '.$requirement->statusLabel(),
            ])
            ->all();
    }

    protected function canDecide(): bool
    {
        $workOrder = $this->getOwnerRecord()->workOrder;

        return $workOrder !== null
            && auth()->user()?->can('decideDocumentReview', $workOrder) === true
            && $this->requirementOptions() !== [];
    }
}
