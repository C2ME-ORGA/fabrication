<?php

namespace App\Filament\Resources\NonConformities\Schemas;

use App\Enums\CorrectiveActionStatus;
use App\Enums\NonConformityVerificationResult;
use App\Models\NonConformity;
use App\Models\QualityAuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NonConformityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identification')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Référence')
                            ->weight('bold'),
                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge(),
                        TextEntry::make('severity')
                            ->label('Gravité')
                            ->badge(),
                        TextEntry::make('category')
                            ->label('Catégorie')
                            ->badge(),
                        TextEntry::make('origin')
                            ->label('Origine')
                            ->badge(),
                        TextEntry::make('work_order_number')
                            ->label('OF')
                            ->placeholder('—'),
                        TextEntry::make('detected_at')
                            ->label('Déclarée le')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('detector.name')
                            ->label('Déclarée par')
                            ->placeholder('—'),
                        TextEntry::make('creator.name')
                            ->label('Créée par')
                            ->placeholder('—'),
                    ]),
                Section::make('Rattachement source')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('operation.code')
                            ->label('Opération')
                            ->placeholder('—'),
                        TextEntry::make('item.mark')
                            ->label('Repère')
                            ->placeholder('—'),
                        TextEntry::make('control.template_code')
                            ->label('Contrôle qualité')
                            ->placeholder('—'),
                        TextEntry::make('result.id')
                            ->label('Résultat source')
                            ->placeholder('—')
                            ->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : '#'.$state),
                        TextEntry::make('result.display_value')
                            ->label('Valeur du résultat')
                            ->state(fn (NonConformity $record): string => $record->result?->displayValue() ?? '—'),
                        TextEntry::make('result.performed_at')
                            ->label('Résultat saisi le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),
                Section::make('Anomalie')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('description')
                            ->label('Description factuelle')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('criterion')
                            ->label('Exigence ou critère non respecté')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('root_cause')
                            ->label('Cause racine')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('root_cause_method')
                            ->label('Méthode d\'analyse')
                            ->placeholder('—'),
                    ]),
                Section::make('Traitement et qualification')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('responsible.name')
                            ->label('Responsable')
                            ->placeholder('—'),
                        TextEntry::make('due_date')
                            ->label('Échéance')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('taken_in_charge_at')
                            ->label('Prise en charge le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('qualifier.name')
                            ->label('Qualifiée par')
                            ->placeholder('—'),
                        TextEntry::make('qualified_at')
                            ->label('Qualifiée le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('verification_due_at')
                            ->label('Vérification due le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('qualification_comment')
                            ->label('Motif de qualification')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('treatment_plan')
                            ->label('Traitement envisagé')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('immediate_action')
                            ->label('Action immédiate / confinement')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('containment_at')
                            ->label('Confinement réalisé le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('treated_at')
                            ->label('Traitement terminé le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),
                Section::make('Politiques de gravité')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('initial_severity')
                            ->label('Gravité initiale')
                            ->badge(),
                        TextEntry::make('severity')
                            ->label('Gravité retenue')
                            ->badge(),
                        TextEntry::make('initial_policy_blocking')
                            ->label('Initiale bloquante')
                            ->state(fn (NonConformity $record): string => ($record->severity_policy_snapshot['blocking'] ?? false) ? 'Oui' : 'Non'),
                        TextEntry::make('current_policy_blocking')
                            ->label('Courante bloquante')
                            ->state(fn (NonConformity $record): string => ($record->currentPolicy()['blocking'] ?? false) ? 'Oui' : 'Non'),
                        TextEntry::make('initial_policy_verification')
                            ->label('Vérification initiale')
                            ->state(fn (NonConformity $record): string => ($record->severity_policy_snapshot['verification_required'] ?? false) ? 'Oui' : 'Non'),
                        TextEntry::make('current_policy_verification')
                            ->label('Vérification courante')
                            ->state(fn (NonConformity $record): string => ($record->currentPolicy()['verification_required'] ?? false) ? 'Oui' : 'Non'),
                        TextEntry::make('initial_policy_counter_control')
                            ->label('Contre-contrôle initial')
                            ->state(fn (NonConformity $record): string => ($record->severity_policy_snapshot['counter_control_required'] ?? false) ? 'Oui' : 'Non'),
                        TextEntry::make('current_policy_counter_control')
                            ->label('Contre-contrôle courant')
                            ->state(fn (NonConformity $record): string => ($record->currentPolicy()['counter_control_required'] ?? false) ? 'Oui' : 'Non'),
                        TextEntry::make('initial_policy_version')
                            ->label('Version initiale')
                            ->state(fn (NonConformity $record): string => (string) ($record->severity_policy_snapshot['config_version'] ?? '—')),
                        TextEntry::make('current_policy_version')
                            ->label('Version courante')
                            ->state(fn (NonConformity $record): string => (string) ($record->currentPolicy()['config_version'] ?? '—')),
                        TextEntry::make('requalification_history')
                            ->label('Historique des requalifications')
                            ->state(fn (NonConformity $record): array => QualityAuditLog::query()
                                ->where('subject_type', 'non_conformity')
                                ->where('subject_id', $record->getKey())
                                ->whereIn('event', ['non_conformity_qualified', 'non_conformity_severity_changed'])
                                ->orderBy('id')
                                ->get()
                                ->map(fn (QualityAuditLog $log): string => sprintf(
                                    '%s — %s → %s — %s',
                                    $log->created_at?->timezone(config('quality.display_timezone', 'Europe/Paris'))->format('d/m/Y H:i') ?? '',
                                    (string) ($log->metadata['previous_severity'] ?? '—'),
                                    (string) ($log->metadata['severity'] ?? '—'),
                                    (string) ($log->comment ?? '—'),
                                ))
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Clôture')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('closed_at')
                            ->label('Clôturée le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('closer.name')
                            ->label('Clôturée par')
                            ->placeholder('—'),
                        TextEntry::make('closure_comment')
                            ->label('Commentaire de clôture')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('actions_summary')
                            ->label('Actions correctives')
                            ->state(fn (NonConformity $record): string => $record->correctiveActions()
                                ->get()
                                ->groupBy(fn ($action): string => $action->status->value)
                                ->map(fn ($group, string $status): string => CorrectiveActionStatus::from($status)->getLabel().' : '.$group->count())
                                ->implode(' · '))
                            ->columnSpanFull(),
                        TextEntry::make('verifications_summary')
                            ->label('Vérifications')
                            ->state(fn (NonConformity $record): string => $record->verifications()
                                ->get()
                                ->map(fn ($verification): string => $verification->type->getLabel().' — '.NonConformityVerificationResult::from($verification->result->value)->getLabel())
                                ->implode(' · '))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
