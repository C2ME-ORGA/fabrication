<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Origine d'une non-conformité.
 */
enum NonConformityOrigin: string implements HasLabel
{
    case ControlResult = 'control_result';
    case Operation = 'operation';
    case Item = 'item';
    case WorkOrder = 'work_order';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::ControlResult => 'Résultat de contrôle',
            self::Operation => 'Opération',
            self::Item => 'Repère / pièce',
            self::WorkOrder => 'Ordre de fabrication',
            self::External => 'Origine externe',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $origin): array => $options + [$origin->value => $origin->getLabel()],
            [],
        );
    }
}
