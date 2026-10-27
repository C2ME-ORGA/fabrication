<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentVersionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::PendingValidation => 'En attente de validation',
            self::Approved => 'Validée',
            self::Rejected => 'Refusée',
            self::Superseded => 'Remplacée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingValidation => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Superseded => 'info',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingValidation],
            self::PendingValidation => [self::Approved, self::Rejected],
            self::Approved => [self::Superseded],
            self::Rejected, self::Superseded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Superseded], true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
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
