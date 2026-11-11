<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\NonConformity;
use App\Models\QualityDeviation;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Services\DocumentApplicabilityService;
use App\Services\QualityClosureEligibilityService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Joaopaulolndev\FilamentPdfViewer\Infolists\Components\PdfViewerEntry;

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
                        TextEntry::make('responsible.name')
                            ->label('Responsable opérationnel')
                            ->placeholder('—'),
                        TextEntry::make('beApprover.name')
                            ->label('Approuvé par (BE)')
                            ->placeholder('—'),
                        TextEntry::make('be_approved_at')
                            ->label('Approbation BE le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i'),
                    ]),
                Section::make('Éligibilité qualité')
                    ->description('Contrôles obligatoires, non-conformités et dérogations influençant la libération ou la clôture.')
                    ->schema([
                        Html::make(function (WorkOrder $record): string {
                            $eligibility = app(QualityClosureEligibilityService::class)->evaluate($record);

                            return view('filament.work-orders.quality-eligibility', [
                                'workOrder' => $record,
                                'eligibility' => $eligibility,
                                'controls' => WorkOrderQualityControl::query()
                                    ->whereKey($eligibility->controlIds)
                                    ->get(),
                                'nonConformities' => NonConformity::query()
                                    ->whereKey($eligibility->nonConformityIds)
                                    ->get(),
                                'deviations' => QualityDeviation::query()
                                    ->whereKey($eligibility->deviationIds)
                                    ->get(),
                            ])->render();
                        }),
                    ])
                    ->columnSpanFull(),

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

                Section::make('PDF généré')
                    ->description('Dernière génération archivée. Le fichier est servi par une route authentifiée ; aucun chemin privé n’est exposé.')
                    ->schema([
                        PdfViewerEntry::make('generated_pdf')
                            ->label('PDF archivé')
                            ->minHeight('70svh')
                            ->fileUrl(fn (WorkOrder $record): ?string => ($document = $record->latestGeneratedDocument())
                                ? route('generated-documents.view', $document)
                                : null)
                            ->visible(fn (WorkOrder $record): bool => $record->latestGeneratedDocument() !== null),
                        Html::make(function (WorkOrder $record): string {
                            $document = $record->latestGeneratedDocument();

                            if ($document === null) {
                                return '<span class="text-sm text-gray-500">Aucun PDF archivé pour cet OF.</span>';
                            }

                            $viewUrl = e(route('generated-documents.view', $document));
                            $downloadUrl = e(route('generated-documents.download', $document));

                            return '<div class="flex flex-wrap items-center gap-4 text-sm">'
                                .'<span>Référence : <strong>'.e($document->reference).'</strong></span>'
                                .'<a class="text-primary-600 underline" target="_blank" rel="noopener" href="'.$viewUrl.'">Ouvrir</a>'
                                .'<a class="text-primary-600 underline" href="'.$downloadUrl.'">Télécharger</a>'
                                .'</div>';
                        }),
                    ])
                    ->columnSpanFull(),

                Section::make('Historique des actions')
                    ->description('Journal immuable de la création et des transitions d’état de cet OF.')
                    ->schema([
                        Html::make(function (WorkOrder $record): string {
                            return view('filament.work-orders.audit-log', [
                                'workOrder' => $record,
                                'auditLogs' => $record->auditLogs()->with('user')->latest('created_at')->get(),
                            ])->render();
                        }),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
