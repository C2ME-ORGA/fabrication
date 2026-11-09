<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Type d'entité visé par une entrée du journal d'audit qualité.
 *
 * Il s'agit d'une référence typée explicite, et non d'une relation
 * polymorphe Eloquent : le projet n'utilise pas de morphTo et la trace doit
 * survivre à la suppression de l'entité (elle conserve une référence lisible).
 */
enum QualityAuditSubject: string implements HasLabel
{
    case Control = 'control';
    case ControlPoint = 'control_point';
    case Result = 'result';
    case NonConformity = 'non_conformity';
    case CorrectiveAction = 'corrective_action';
    case Verification = 'verification';
    case Deviation = 'deviation';
    case TemplateVersion = 'template_version';
    case Template = 'template';
    case Requirement = 'requirement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Control => 'Contrôle',
            self::ControlPoint => 'Point de contrôle',
            self::Result => 'Résultat',
            self::NonConformity => 'Non-conformité',
            self::CorrectiveAction => 'Action corrective',
            self::Verification => 'Vérification',
            self::Deviation => 'Dérogation',
            self::TemplateVersion => 'Version de modèle',
            self::Template => 'Modèle de contrôle',
            self::Requirement => 'Exigence qualité',
        };
    }
}
