<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GeneratedDocumentStatus: string implements HasColor, HasLabel
{
    case Archived = 'archived';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Archived => 'Archivé',
            self::Superseded => 'Remplacé',
            self::Cancelled => 'Annulé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Archived => 'success',
            self::Superseded => 'gray',
            self::Cancelled => 'danger',
        };
    }

    public function isCurrent(): bool
    {
        return $this === self::Archived;
    }
}
