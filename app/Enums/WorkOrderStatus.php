<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Released = 'released';
    case InProgress = 'in_progress';
    case QualityCheck = 'quality_check';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ready => 'Prêt',
            self::Released => 'Libéré',
            self::InProgress => 'En cours',
            self::QualityCheck => 'Contrôle qualité',
            self::Completed => 'Terminé',
            self::Cancelled => 'Annulé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ready => 'info',
            self::Released => 'primary',
            self::InProgress => 'warning',
            self::QualityCheck => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Allowed status transitions. Anything not listed here is invalid.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ready, self::Cancelled],
            self::Ready => [self::Released, self::Cancelled],
            self::Released => [self::InProgress, self::Cancelled],
            self::InProgress => [self::QualityCheck, self::Cancelled],
            self::QualityCheck => [self::InProgress, self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Completed and cancelled work orders are frozen for ordinary edits.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
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
