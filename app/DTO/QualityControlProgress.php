<?php

namespace App\DTO;

/**
 * Avancement d'un point de contrôle généré.
 *
 * `evaluationRule` et `retainedResultIds` rendent explicite la règle
 * d'évaluation appliquée : en portée item, dernier résultat validé par repère ;
 * en portée opération / OF, derniers résultats validés dans la limite de
 * `required_results`. Tous les résultats historiques restent conservés.
 */
final class QualityControlProgress
{
    /**
     * @param  array<int, int>  $retainedResultIds
     */
    public function __construct(
        public readonly int $pointId,
        public readonly int $requiredResults,
        public readonly int $conformingResults,
        public readonly int $retainedResults,
        public readonly bool $satisfied,
        public readonly bool $hasNonConformity,
        public readonly bool $hasPendingValidation,
        public readonly string $evaluationRule = 'latest_validated_result_per_item',
        public readonly array $retainedResultIds = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'point_id' => $this->pointId,
            'required_results' => $this->requiredResults,
            'conforming_results' => $this->conformingResults,
            'retained_results' => $this->retainedResults,
            'satisfied' => $this->satisfied,
            'has_non_conformity' => $this->hasNonConformity,
            'has_pending_validation' => $this->hasPendingValidation,
            'evaluation_rule' => $this->evaluationRule,
            'retained_result_ids' => $this->retainedResultIds,
        ];
    }
}
