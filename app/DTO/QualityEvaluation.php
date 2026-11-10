<?php

namespace App\DTO;

/**
 * Résultat de l'évaluation automatique d'une valeur de contrôle.
 *
 * `isConform` reste null lorsque le type ne permet aucune évaluation
 * automatique certaine (mesure informative sans borne). Le détail conserve la
 * règle appliquée afin de permettre une réévaluation historique.
 */
final class QualityEvaluation
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public readonly ?bool $isConform,
        public readonly array $detail,
    ) {}
}
