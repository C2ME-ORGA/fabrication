<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Événements du journal d'audit qualité.
 *
 * L'enum couvre l'ensemble du domaine, mais chaque lot n'émet que les
 * événements qu'il implémente. Les entrées sont append-only.
 */
enum QualityAuditEvent: string implements HasLabel
{
    // Référentiel
    case TemplateCreated = 'template_created';
    case TemplateUpdated = 'template_updated';
    case TemplateVersionCreated = 'template_version_created';
    case TemplateVersionActivated = 'template_version_activated';
    case TemplateVersionRetired = 'template_version_retired';
    case TemplateVersionDeleted = 'template_version_deleted';
    case TemplateDeleted = 'template_deleted';
    case PointAdded = 'point_added';
    case PointUpdated = 'point_updated';
    case PointRemoved = 'point_removed';
    case RequirementAdded = 'requirement_added';
    case RequirementUpdated = 'requirement_updated';
    case RequirementRemoved = 'requirement_removed';

    // Contrôles et résultats (lots suivants)
    case ControlGenerated = 'control_generated';
    case ControlAddedManually = 'control_added_manually';
    case ControlCancelled = 'control_cancelled';
    case ControlDeleted = 'control_deleted';
    case ControlRequired = 'control_required';
    case SamplingPlanRecalculated = 'sampling_plan_recalculated';
    case ResultRecorded = 'result_recorded';
    case ResultValidated = 'result_validated';
    case ResultRejected = 'result_rejected';
    case ResultSuperseded = 'result_superseded';
    case ControlStatusChanged = 'control_status_changed';

    // Non-conformités (lot 4)
    case NonConformityCreated = 'non_conformity_created';
    case NonConformityQualified = 'non_conformity_qualified';
    case NonConformitySeverityChanged = 'non_conformity_severity_changed';
    case NonConformityAssigned = 'non_conformity_assigned';
    case NonConformityStatusChanged = 'non_conformity_status_changed';
    case NonConformityClosed = 'non_conformity_closed';
    case NonConformityReopened = 'non_conformity_reopened';
    case NonConformityEvidenceAdded = 'non_conformity_evidence_added';
    case CorrectiveActionAdded = 'corrective_action_added';
    case CorrectiveActionStatusChanged = 'corrective_action_status_changed';
    case CorrectiveActionCompleted = 'corrective_action_completed';
    case CorrectiveActionIneffective = 'corrective_action_ineffective';
    case VerificationRecorded = 'verification_recorded';
    case SeverityPolicyApplied = 'severity_policy_applied';

    // Dérogations (lot 5)
    case DeviationRequested = 'deviation_requested';
    case DeviationApproved = 'deviation_approved';
    case DeviationRejected = 'deviation_rejected';
    case DeviationGranted = 'deviation_granted';
    case DeviationEvidenceAdded = 'deviation_evidence_added';
    case DeviationRevoked = 'deviation_revoked';
    case DeviationExpired = 'deviation_expired';

    // Clôture (lot 5)
    case ClosureEvaluated = 'closure_evaluated';
    case ClosureBlocked = 'closure_blocked';
    case WorkOrderCancelledWithOpenQualityIssues = 'work_order_cancelled_with_open_quality_issues';

    public function getLabel(): string
    {
        return match ($this) {
            self::TemplateCreated => 'Création d\'un modèle de contrôle',
            self::TemplateUpdated => 'Modification d\'un modèle de contrôle',
            self::TemplateVersionCreated => 'Création d\'une version de modèle',
            self::TemplateVersionActivated => 'Activation d\'une version de modèle',
            self::TemplateVersionRetired => 'Retrait d\'une version de modèle',
            self::TemplateVersionDeleted => 'Suppression d\'une version brouillon',
            self::TemplateDeleted => 'Suppression d\'un modèle de contrôle',
            self::PointAdded => 'Ajout d\'un point de contrôle',
            self::PointUpdated => 'Modification d\'un point de contrôle',
            self::PointRemoved => 'Suppression d\'un point de contrôle',
            self::RequirementAdded => 'Ajout d\'une exigence qualité',
            self::RequirementUpdated => 'Modification d\'une exigence qualité',
            self::RequirementRemoved => 'Suppression d\'une exigence qualité',
            self::ControlGenerated => 'Génération d\'un contrôle',
            self::ControlAddedManually => 'Ajout manuel d\'un contrôle',
            self::ControlCancelled => 'Annulation d\'un contrôle',
            self::ControlDeleted => 'Suppression d\'un contrôle',
            self::ControlRequired => 'Contrôle devenu exigible',
            self::SamplingPlanRecalculated => 'Recalcul du plan d\'échantillonnage',
            self::ResultRecorded => 'Saisie d\'un résultat',
            self::ResultValidated => 'Validation d\'un résultat',
            self::ResultRejected => 'Rejet d\'un résultat',
            self::ResultSuperseded => 'Remplacement d\'un résultat',
            self::ControlStatusChanged => 'Changement de statut d\'un contrôle',
            self::NonConformityCreated => 'Création d\'une non-conformité',
            self::NonConformityQualified => 'Qualification d\'une non-conformité',
            self::NonConformitySeverityChanged => 'Changement de gravité d\'une non-conformité',
            self::NonConformityAssigned => 'Affectation d\'une non-conformité',
            self::NonConformityStatusChanged => 'Changement de statut d\'une non-conformité',
            self::NonConformityClosed => 'Clôture d\'une non-conformité',
            self::NonConformityReopened => 'Réouverture d\'une non-conformité',
            self::NonConformityEvidenceAdded => 'Ajout d\'une preuve de non-conformité',
            self::CorrectiveActionAdded => 'Ajout d\'une action corrective',
            self::CorrectiveActionStatusChanged => 'Changement de statut d\'une action corrective',
            self::CorrectiveActionCompleted => 'Terminaison d\'une action corrective',
            self::CorrectiveActionIneffective => 'Action corrective inefficace',
            self::VerificationRecorded => 'Enregistrement d\'une vérification',
            self::SeverityPolicyApplied => 'Application de la politique de gravité',
            self::DeviationGranted => 'Octroi d\'une dérogation',
            self::DeviationRequested => 'Demande de dérogation',
            self::DeviationApproved => 'Approbation d\'une dérogation',
            self::DeviationRejected => 'Refus d\'une dérogation',
            self::DeviationEvidenceAdded => 'Ajout d\'une preuve de dérogation',
            self::DeviationRevoked => 'Révocation d\'une dérogation',
            self::DeviationExpired => 'Expiration d\'une dérogation',
            self::ClosureEvaluated => 'Évaluation de l\'éligibilité à la clôture',
            self::ClosureBlocked => 'Clôture bloquée pour motif qualité',
            self::WorkOrderCancelledWithOpenQualityIssues => 'Annulation avec non-conformités ouvertes',
        };
    }
}
