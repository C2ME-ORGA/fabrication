<?php

namespace App\Filament\Workshop\Resources\WorkshopOperations\Schemas;

use App\Models\WorkOrderOperation;
use App\Services\OperationTimeAggregationService;
use App\Support\DurationFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkshopOperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informations générales')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('workOrder.number')->label('OF'),
                        TextEntry::make('workOrder.job.code')->label('Affaire'),
                        TextEntry::make('workOrder.job.name')->label('Chantier'),
                        TextEntry::make('workOrder.priority')->label('Priorité')->badge(),
                        TextEntry::make('code')->label('Code opération'),
                        TextEntry::make('name')->label('Opération'),
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('is_mandatory')
                            ->label('Obligatoire')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Oui' : 'Non'),
                        TextEntry::make('instructions')
                            ->label('Consignes de fabrication')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
                Section::make('Dépendances')
                    ->schema([
                        RepeatableEntry::make('dependencies')
                            ->hiddenLabel()
                            ->placeholder('Aucune dépendance')
                            ->schema([
                                TextEntry::make('code')->label('Code'),
                                TextEntry::make('name')->label('Opération préalable'),
                                TextEntry::make('status')->label('Statut')->badge(),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Suivi du temps')
                    ->poll('5s')
                    ->schema([
                        Html::make(fn (WorkOrderOperation $record): string => view(
                            'filament.workshop.partials.operation-time-summary',
                            ['summary' => app(OperationTimeAggregationService::class)->forOperation($record)],
                        )->render()),
                        RepeatableEntry::make('timeEntries')
                            ->label('Sessions de temps')
                            ->placeholder('Aucune session de temps')
                            ->schema([
                                TextEntry::make('user.name')->label('Opérateur'),
                                TextEntry::make('entry_type')->label('Type')->badge(),
                                TextEntry::make('active')
                                    ->label('État')
                                    ->state(fn ($record): string => $record->ended_at === null ? 'En cours' : 'Clôturée')
                                    ->badge()
                                    ->color(fn (string $state): string => $state === 'En cours' ? 'warning' : 'gray'),
                                TextEntry::make('started_at')
                                    ->label('Début')
                                    ->dateTime('d/m/Y H:i:s')
                                    ->timezone('Europe/Paris'),
                                TextEntry::make('ended_at')
                                    ->label('Fin')
                                    ->dateTime('d/m/Y H:i:s')
                                    ->timezone('Europe/Paris')
                                    ->placeholder('Session active'),
                                TextEntry::make('live_duration')
                                    ->label('Durée')
                                    ->state(fn ($record): string => $record->ended_at !== null
                                        ? DurationFormatter::format((int) $record->duration_seconds, true)
                                        : DurationFormatter::format(
                                            max(0, now()->getTimestamp() - $record->started_at->getTimestamp()),
                                            true,
                                        )),
                                TextEntry::make('correction_reason')
                                    ->label('Correction')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Historique')
                    ->schema([
                        RepeatableEntry::make('auditLogs')
                            ->hiddenLabel()
                            ->placeholder('Aucun événement')
                            ->schema([
                                TextEntry::make('event')->label('Événement')->badge(),
                                TextEntry::make('user.name')->label('Auteur')->placeholder('Système'),
                                TextEntry::make('created_at')
                                    ->label('Date')
                                    ->dateTime('d/m/Y H:i:s')
                                    ->timezone('Europe/Paris'),
                                TextEntry::make('from_status')->label('Ancien statut')->placeholder('—'),
                                TextEntry::make('to_status')->label('Nouveau statut')->placeholder('—'),
                                TextEntry::make('comment')->label('Commentaire')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
