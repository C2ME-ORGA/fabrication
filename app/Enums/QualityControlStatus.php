<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'un contrôle qualité généré dans un ordre de fabrication.
 *
 * Le statut est recalculé par le service d'évaluation après chaque saisie ou
 * validation. Il n'est jamais écrit directement par un formulaire.
 */
enum QualityControlStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Passed = 'passed';
    case Failed = 'failed';
    case Waived = 'waived';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::InProgress => 'En cours',
            self::Passed => 'Conforme',
            self::Failed => 'Non conforme',
            self::Waived => 'Dérogé',
            self::Cancelled => 'Annulé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'warning',
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Waived => 'info',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Un contrôle passé, dérogé ou annulé ne peut plus recevoir de résultat.
     * Un contrôle non conforme reste ouvert au recontrôle.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Passed, self::Waived, self::Cancelled], true);
    }

    public function allowsResultEntry(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::Failed], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $status): array => $options + [$status->value => $status->getLabel()],
            [],
        );
    }
}
