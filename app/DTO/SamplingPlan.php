<?php

namespace App\DTO;

use App\Enums\QualityControlSamplingMode;

/**
 * Plan d'échantillonnage figé pour un point généré.
 *
 * `selectedItemIds` contient la sélection déterministe persistée pour les
 * modes `all` et `every_n`. En mode `fixed`, la sélection reste libre mais le
 * nombre attendu est celui saisi au modèle.
 */
final class SamplingPlan
{
    /**
     * @param  array<int, int>  $selectedItemIds
     */
    public function __construct(
        public readonly QualityControlSamplingMode $mode,
        public readonly ?int $samplingEvery,
        public readonly int $requiredResults,
        public readonly int $applicableItems,
        public readonly array $selectedItemIds,
    ) {}
}
