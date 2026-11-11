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

    /**
     * Indique si le document est la version courante de sa portée.
     *
     * `Archived` désigne la version archivée et courante. Une nouvelle
     * génération supersède la précédente : `Superseded` n'est donc plus
     * courante, tout comme `Cancelled`. Ce vocabulaire diffère de « archivé
     * au sens historique » : l'archivage FC-8 est l'acte de publication de la
     * version courante.
     */
    public function isCurrent(): bool
    {
        return $this === self::Archived;
    }
}
