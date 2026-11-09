<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Événements du journal d'audit des opérations d'un ordre de fabrication.
 *
 * Chaque action sensible produit une entrée immuable dans la même transaction
 * que la mutation métier correspondante.
 */
enum WorkshopOperationAuditEvent: string implements HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Ready = 'ready';
    case DependenciesModified = 'dependencies_modified';
    case RoutingApplied = 'routing_applied';
    case RoutingChanged = 'routing_changed';
    case RoutingOperationsDeleted = 'routing_operations_deleted';
    case RoutingOverrideApplied = 'routing_override_applied';
    case Started = 'started';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Blocked = 'blocked';
    case Unblocked = 'unblocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case TimerStarted = 'timer_started';
    case TimerStopped = 'timer_stopped';
    case TimerAutoStopped = 'timer_auto_stopped';
    case ManualTimeRecorded = 'manual_time_recorded';
    case TimeCorrected = 'time_corrected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Création de l\'opération',
            self::Updated => 'Modification de l\'opération',
            self::Deleted => 'Suppression de l\'opération',
            self::Ready => 'Opération devenue réalisable',
            self::DependenciesModified => 'Modification des dépendances',
            self::RoutingApplied => 'Application d\'une gamme',
            self::RoutingChanged => 'Changement de gamme',
            self::RoutingOperationsDeleted => 'Suppression des opérations de l\'ancienne gamme',
            self::RoutingOverrideApplied => 'Modification exceptionnelle après libération',
            self::Started => 'Démarrage de l\'opération',
            self::Paused => 'Mise en pause',
            self::Resumed => 'Reprise de l\'opération',
            self::Blocked => 'Blocage de l\'opération',
            self::Unblocked => 'Déblocage de l\'opération',
            self::Completed => 'Terminaison de l\'opération',
            self::Cancelled => 'Annulation de l\'opération',
            self::TimerStarted => 'Démarrage d\'un chronomètre',
            self::TimerStopped => 'Arrêt d\'un chronomètre',
            self::TimerAutoStopped => 'Arrêt automatique d\'un chronomètre',
            self::ManualTimeRecorded => 'Saisie manuelle de temps',
            self::TimeCorrected => 'Correction d\'un temps',
        };
    }
}
