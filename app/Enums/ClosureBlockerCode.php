<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Codes de blocage du calcul d'éligibilité qualité.
 */
enum ClosureBlockerCode: string implements HasLabel
{
    case MissingMandatoryControl = 'missing_mandatory_control';
    case NonConformingMandatoryControl = 'non_conforming_mandatory_control';
    case UnvalidatedMandatoryResult = 'unvalidated_mandatory_result';
    case OpenBlockingNonConformity = 'open_blocking_non_conformity';
    case PendingVerificationNonConformity = 'pending_verification_non_conformity';
    case IneffectiveCorrectiveAction = 'ineffective_corrective_action';
    case ExpiredDeviation = 'expired_deviation';
    case MissingFinalDocumentaryControl = 'missing_final_documentary_control';
    case QualityPlanIncomplete = 'quality_plan_incomplete';

    public function getLabel(): string
    {
        return match ($this) {
            self::MissingMandatoryControl => 'Contrôle obligatoire manquant',
            self::NonConformingMandatoryControl => 'Contrôle obligatoire non conforme',
            self::UnvalidatedMandatoryResult => 'Validation obligatoire manquante',
            self::OpenBlockingNonConformity => 'Non-conformité bloquante',
            self::PendingVerificationNonConformity => 'Non-conformité en attente de vérification',
            self::IneffectiveCorrectiveAction => 'Action corrective inefficace',
            self::ExpiredDeviation => 'Dérogation expirée',
            self::MissingFinalDocumentaryControl => 'Contrôle final documentaire manquant',
            self::QualityPlanIncomplete => 'Plan qualité incomplet',
        };
    }
}
