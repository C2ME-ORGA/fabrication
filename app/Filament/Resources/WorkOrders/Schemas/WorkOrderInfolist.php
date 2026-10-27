<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\WorkOrder;
use App\Services\DocumentApplicabilityService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ordre de fabrication')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('number')
                            ->label("Numéro d'OF"),
                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge(),
                        TextEntry::make('job.code')
                            ->label('Affaire')
                            ->url(fn (WorkOrder $record): string => JobResource::getUrl('view', ['record' => $record->job_id])),
                        TextEntry::make('job.client_name')
                            ->label('Client'),
                        TextEntry::make('title')
                            ->label('Désignation')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('priority')
                            ->label('Priorité')
                            ->badge(),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Planification et suivi')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('planned_start_at')
                            ->label('Début prévisionnel')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('planned_end_at')
                            ->label('Fin prévisionnelle')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('actual_start_at')
                            ->label('Début réel')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('actual_end_at')
                            ->label('Fin réelle')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('creator.name')
                            ->label('Créé par')
                            ->placeholder('—'),
                        TextEntry::make('releaser.name')
                            ->label('Libéré par')
                            ->placeholder('—'),
                        TextEntry::make('released_at')
                            ->label('Libéré le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i'),
                    ]),
                Section::make('Documents techniques')
                    ->description('Versions approuvées applicables, références figées à la libération et remises en revue explicites.')
                    ->schema([
                        Html::make(function (WorkOrder $record): string {
                            $applicability = app(DocumentApplicabilityService::class);

                            return view('filament.work-orders.documents', [
                                'workOrder' => $record,
                                'applicableDocuments' => $applicability->applicableDocumentsFor($record),
                                'snapshots' => $applicability->snapshotsWithImpact($record),
                                'releaseSnapshots' => $applicability->releaseSnapshotsFor($record),
                            ])->render();
                        }),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
