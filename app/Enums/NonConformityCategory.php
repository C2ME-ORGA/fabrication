<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Catégorie métier d'une non-conformité.
 */
enum NonConformityCategory: string implements HasLabel
{
    case Dimensional = 'dimensional';
    case Visual = 'visual';
    case Welding = 'welding';
    case Material = 'material';
    case Documentation = 'documentation';
    case Process = 'process';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dimensional => 'Dimensionnel',
            self::Visual => 'Visuel',
            self::Welding => 'Soudure',
            self::Material => 'Matière',
            self::Documentation => 'Documentation',
            self::Process => 'Process',
            self::Other => 'Autre',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $options, self $category): array => $options + [$category->value => $category->getLabel()],
            [],
        );
    }
}
