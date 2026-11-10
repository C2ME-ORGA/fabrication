<?php

namespace App\Services;

use App\DTO\SamplingPlan;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityRequirementScope;
use Illuminate\Validation\ValidationException;

/**
 * Règles d'échantillonnage d'un point de contrôle.
 *
 * Le mode est défini au niveau du point. Le nombre de résultats réellement
 * attendus dépend du nombre de repères applicables et n'est figé qu'à la
 * génération du plan qualité de l'OF (Lot 3). Au niveau du référentiel,
 * `all` et `every_n` conservent donc la valeur minimale 1.
 */
class QualityControlSamplingService
{
    /**
     * Vérifie la cohérence intrinsèque de la configuration d'un point.
     *
     * @throws ValidationException
     */
    public function assertValidPointConfiguration(
        QualityControlSamplingMode $mode,
        ?int $samplingEvery,
        int $requiredResults,
    ): void {
        if ($requiredResults < 1) {
            throw ValidationException::withMessages([
                'required_results' => 'Le nombre de résultats attendus doit être au moins égal à 1.',
            ]);
        }

        if ($mode === QualityControlSamplingMode::EveryN) {
            if ($samplingEvery === null || $samplingEvery < 2) {
                throw ValidationException::withMessages([
                    'sampling_every' => 'Le mode « une pièce sur N » exige une fréquence strictement supérieure à 1.',
                ]);
            }

            return;
        }

        if ($samplingEvery !== null) {
            throw ValidationException::withMessages([
                'sampling_every' => 'La fréquence d\'échantillonnage n\'est utilisable qu\'avec le mode « une pièce sur N ».',
            ]);
        }
    }

    /**
     * Vérifie qu'une portée d'exigence accepte le mode d'échantillonnage.
     *
     * @throws ValidationException
     */
    public function assertScopeSupportsMode(
        QualityRequirementScope $scope,
        QualityControlSamplingMode $mode,
        ?string $field = null,
    ): void {
        if ($mode === QualityControlSamplingMode::EveryN && ! $scope->allowsFrequencySampling()) {
            throw ValidationException::withMessages([
                $field ?? 'sampling_mode' => 'Le mode « une pièce sur N » n\'est possible que sur un contrôle au niveau du repère ou de la pièce.',
            ]);
        }
    }

    /**
     * Nombre de résultats attendus.
     *
     * @param  int  $applicableItems  Nombre de repères applicables (ou 1 pour
     *                                une portée opération / ordre de fabrication).
     */
    public function computeRequiredResults(
        QualityControlSamplingMode $mode,
        ?int $samplingEvery,
        int $configuredRequiredResults,
        int $applicableItems,
    ): int {
        $applicableItems = max(1, $applicableItems);

        return max(1, match ($mode) {
            QualityControlSamplingMode::All => $applicableItems,
            QualityControlSamplingMode::EveryN => (int) ceil($applicableItems / max(2, (int) $samplingEvery)),
            QualityControlSamplingMode::Fixed => $configuredRequiredResults,
        });
    }

    /**
     * Libellé lisible du mode, utilisé dans les écrans.
     */
    public function describe(
        QualityControlSamplingMode $mode,
        ?int $samplingEvery = null,
        ?int $requiredResults = null,
    ): string {
        return match ($mode) {
            QualityControlSamplingMode::All => 'Toutes les pièces applicables',
            QualityControlSamplingMode::EveryN => 'Une pièce sur '.(int) $samplingEvery,
            QualityControlSamplingMode::Fixed => 'Nombre fixé de pièces : '.(int) $requiredResults,
        };
    }

    /**
     * Construit le plan d'échantillonnage figé d'un contrôle généré.
     *
     * La sélection `every_n` est déterministe : les repères sont triés par
     * identifiant croissant et un repère sur N est retenu (indices 0, N,
     * 2N…). La sélection existante est préservée lors d'un recalcul afin
     * qu'une consultation ou un ajout ultérieur ne change jamais les repères
     * déjà prévus.
     *
     * @param  array<int, int|string>  $itemIds  Repères de l'OF, triés par id.
     * @param  array<int, int|string>  $preserve  Sélection déjà persistée.
     */
    public function plan(
        QualityControlSamplingMode $mode,
        ?int $samplingEvery,
        int $configuredRequiredResults,
        array $itemIds,
        bool $itemScoped,
        array $preserve = [],
    ): SamplingPlan {
        $itemIds = array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            $itemIds,
        )));
        sort($itemIds);

        $applicableItems = $itemScoped ? count($itemIds) : 1;
        $requiredResults = $this->computeRequiredResults(
            $mode,
            $samplingEvery,
            $configuredRequiredResults,
            $applicableItems,
        );

        if (! $itemScoped) {
            return new SamplingPlan($mode, $samplingEvery, $requiredResults, $applicableItems, []);
        }

        if ($mode === QualityControlSamplingMode::Fixed) {
            // En mode fixed, l'opérateur choisit les pièces ; le nombre attendu
            // est celui du modèle et doit seulement porter sur des repères
            // distincts.
            return new SamplingPlan($mode, $samplingEvery, $requiredResults, $applicableItems, []);
        }

        $preserved = array_values(array_intersect(
            array_map(fn (mixed $id): int => (int) $id, $preserve),
            $itemIds,
        ));

        if ($mode === QualityControlSamplingMode::All) {
            return new SamplingPlan($mode, $samplingEvery, $requiredResults, $applicableItems, $itemIds);
        }

        $step = max(2, (int) $samplingEvery);

        if ($preserved === []) {
            $selected = [];

            foreach ($itemIds as $index => $itemId) {
                if ($index % $step === 0) {
                    $selected[] = $itemId;
                }
            }

            return new SamplingPlan($mode, $samplingEvery, $requiredResults, $applicableItems, $selected);
        }

        $selected = $preserved;

        // Un ajout de repère peut augmenter le nombre attendu. On complète la
        // sélection existante par les plus petits identifiants non retenus :
        // la sélection d'origine n'est jamais remise en cause.
        foreach ($itemIds as $itemId) {
            if (count($selected) >= $requiredResults) {
                break;
            }

            if (! in_array($itemId, $selected, true)) {
                $selected[] = $itemId;
            }
        }

        sort($selected);

        return new SamplingPlan($mode, $samplingEvery, $requiredResults, $applicableItems, $selected);
    }
}
