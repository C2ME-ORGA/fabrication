<?php

namespace App\Filament\Resources\QualityDeviations\Schemas;

use App\Models\QualityDeviation;
use App\Models\QualityDeviationTarget;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QualityDeviationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dérogation')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reference')->label('Référence')->weight('bold'),
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('scope')->label('Type de cible')->badge(),
                        TextEntry::make('work_order_number')->label('OF')->placeholder('—'),
                        TextEntry::make('is_critical')
                            ->label('Critique')
                            ->state(fn (QualityDeviation $record): string => $record->is_critical ? 'Oui' : 'Non'),
                        TextEntry::make('expires_at')
                            ->label('Expiration')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('reason')
                            ->label('Motif détaillé')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label('Justification métier')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Cible')
                    ->schema([
                        TextEntry::make('targets_list')
                            ->label('Cibles précises')
                            ->state(fn (QualityDeviation $record): array => $record->targets
                                ->map(fn (QualityDeviationTarget $target): string => $target->describe())
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                    ]),
                Section::make('Décision et historique')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('requester.name')->label('Demandeur')->placeholder('—'),
                        TextEntry::make('requested_at')->label('Demandée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('granter.name')->label('Premier approbateur')->placeholder('—'),
                        TextEntry::make('granted_at')->label('Première approbation le')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('secondApprover.name')->label('Second approbateur')->placeholder('—'),
                        TextEntry::make('second_approved_at')->label('Seconde approbation le')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('rejecter.name')->label('Refusée par')->placeholder('—'),
                        TextEntry::make('rejected_at')->label('Refusée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('rejection_reason')->label('Motif du refus')->placeholder('—'),
                        TextEntry::make('revoker.name')->label('Révoquée par')->placeholder('—'),
                        TextEntry::make('revoked_at')->label('Révoquée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('revoke_reason')->label('Motif de révocation')->placeholder('—'),
                    ]),
            ]);
    }
}
