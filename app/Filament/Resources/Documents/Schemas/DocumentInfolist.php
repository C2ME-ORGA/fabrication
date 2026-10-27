<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use App\Services\DocumentApplicabilityService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')
                            ->label('Titre'),
                        TextEntry::make('reference')
                            ->label('Référence')
                            ->placeholder('—'),
                        TextEntry::make('category')
                            ->label('Catégorie')
                            ->badge(),
                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge(),
                        TextEntry::make('currentVersion.revision')
                            ->label('Dernière version')
                            ->placeholder('Aucune version'),
                        TextEntry::make('currentVersion.status')
                            ->label('Statut de la dernière version')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('job_reference')
                            ->label('Affaire')
                            ->state(fn (Document $record): ?string => $record->job?->code
                                ?? ($record->workOrder?->job?->code !== null
                                    ? $record->workOrder->job->code.' (via OF)'
                                    : null))
                            ->placeholder('—'),
                        TextEntry::make('workOrder.number')
                            ->label('Ordre de fabrication')
                            ->placeholder('—'),
                        TextEntry::make('creator.name')
                            ->label('Créé par')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Impact des nouvelles révisions')
                    ->schema([
                        Html::make(function (Document $record): string {
                            $latest = $record->latestApprovedVersion;
                            $workOrders = $latest === null
                                ? collect()
                                : app(DocumentApplicabilityService::class)
                                    ->impactedReleasedWorkOrders($record, $latest->version_number);

                            return view('filament.documents.impacted-work-orders', [
                                'document' => $record,
                                'latest' => $latest,
                                'workOrders' => $workOrders,
                            ])->render();
                        }),
                    ]),
            ]);
    }
}
