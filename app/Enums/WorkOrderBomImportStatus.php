<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut d'un import de nomenclature.
 *
 * Seuls les imports confirmés et réussis sont historisés. Un import rejeté
 * n'écrit aucune ligne et ne bloque donc pas une nouvelle tentative avec le
 * même fichier.
 */
enum WorkOrderBomImportStatus: string implements HasColor, HasLabel
{
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Completed => 'Importé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
        };
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
