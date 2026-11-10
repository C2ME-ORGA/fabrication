<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplateVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des points de contrôle d'une version de modèle.
 *
 * Seule une version brouillon est modifiable. Toutes les mutations
 * verrouillent la version puis les points. Les positions sont normalisées
 * par pas de 10 (10, 20, 30…) comme pour les opérations de gamme.
 */
class QualityControlPointService
{
    public function __construct(
        protected QualityControlSamplingService $sampling,
        protected QualityAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(QualityControlTemplateVersion $version, array $data): QualityControlPoint
    {
        $validated = $this->validate($data);

        return DB::transaction(function () use ($version, $validated): QualityControlPoint {
            $lockedVersion = $this->lockVersion($version);
            $this->assertDraft($lockedVersion);

            $point = new QualityControlPoint;
            $point->fill($validated);
            $point->quality_control_template_version_id = $lockedVersion->getKey();
            $point->position = (int) ($validated['position'] ?? $this->nextPosition($lockedVersion));

            $this->assertPositionAvailable($lockedVersion, $point->position);
            $this->applyBusinessRules($point);
            $point->save();

            $this->normalizePositions($lockedVersion);

            $point->refresh();

            $this->audit->record(
                QualityAuditEvent::PointAdded,
                QualityAuditSubject::ControlPoint,
                (int) $point->getKey(),
                $this->pointReference($lockedVersion, $point),
                null,
                null,
                null,
                null,
                'Ajout d\'un point de contrôle.',
                [
                    'version_number' => $lockedVersion->version_number,
                    'label' => $point->label,
                    'result_type' => $point->result_type->value,
                    'sampling_mode' => $point->sampling_mode->value,
                    'required_results' => $point->required_results,
                    'position' => $point->position,
                ],
            );

            return $point;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QualityControlPoint $point, array $data): QualityControlPoint
    {
        $validated = $this->validate($data, partial: true);

        return DB::transaction(function () use ($point, $validated): QualityControlPoint {
            $lockedVersion = $this->lockVersionForPoint($point);
            $this->assertDraft($lockedVersion);

            $lockedPoint = $lockedVersion->points()
                ->whereKey($point->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedPoint === null) {
                $this->fail('point', 'Ce point n\'appartient pas à cette version du modèle.');
            }

            $lockedPoint->fill($validated);

            $previous = [];
            foreach (array_keys($validated) as $field) {
                $previous[$field] = $lockedPoint->getOriginal($field);
            }

            if (array_key_exists('position', $validated)) {
                $lockedPoint->position = (int) $validated['position'];
                $this->assertPositionAvailable(
                    $lockedVersion,
                    (int) $lockedPoint->position,
                    (int) $lockedPoint->getKey(),
                );
            }

            $this->applyBusinessRules($lockedPoint);
            $changed = array_keys($lockedPoint->getDirty());

            if ($changed === []) {
                return $lockedPoint->refresh();
            }

            $lockedPoint->save();

            if (array_key_exists('position', $validated)) {
                $this->normalizePositions($lockedVersion);
            }

            $lockedPoint->refresh();

            $this->audit->record(
                QualityAuditEvent::PointUpdated,
                QualityAuditSubject::ControlPoint,
                (int) $lockedPoint->getKey(),
                $this->pointReference($lockedVersion, $lockedPoint),
                null,
                null,
                null,
                null,
                'Modification d\'un point de contrôle.',
                [
                    'version_number' => $lockedVersion->version_number,
                    'changed_fields' => $changed,
                    'previous' => $previous,
                    'current' => $lockedPoint->getAttributes(),
                ],
            );

            return $lockedPoint;
        }, attempts: 3);
    }

    public function delete(QualityControlPoint $point): void
    {
        DB::transaction(function () use ($point): void {
            $lockedVersion = $this->lockVersionForPoint($point);
            $this->assertDraft($lockedVersion);

            $lockedPoint = $lockedVersion->points()
                ->whereKey($point->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedPoint === null) {
                $this->fail('point', 'Ce point n\'appartient pas à cette version du modèle.');
            }

            $this->audit->record(
                QualityAuditEvent::PointRemoved,
                QualityAuditSubject::ControlPoint,
                (int) $lockedPoint->getKey(),
                $this->pointReference($lockedVersion, $lockedPoint),
                null,
                null,
                null,
                null,
                'Suppression d\'un point de contrôle.',
                [
                    'version_number' => $lockedVersion->version_number,
                    'label' => $lockedPoint->label,
                    'position' => $lockedPoint->position,
                ],
            );

            $lockedPoint->delete();

            $this->normalizePositions($lockedVersion);
        }, attempts: 3);
    }

    /**
     * @param  array<int, int|string>  $orderedPointIds
     */
    public function reorder(QualityControlTemplateVersion $version, array $orderedPointIds): void
    {
        $orderedPointIds = array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            $orderedPointIds,
        )));

        DB::transaction(function () use ($version, $orderedPointIds): void {
            $lockedVersion = $this->lockVersion($version);
            $this->assertDraft($lockedVersion);

            $points = $lockedVersion->points()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existingIds = $points->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $expected = $existingIds;
            sort($expected);
            $provided = $orderedPointIds;
            sort($provided);

            if ($expected !== $provided) {
                $this->fail(
                    'points',
                    'La liste de réorganisation doit contenir exactement tous les points de la version.',
                );
            }

            $positions = [];

            foreach ($orderedPointIds as $index => $pointId) {
                $positions[$pointId] = ($index + 1) * 10;
            }

            $this->writePositions($points, $positions);
        }, attempts: 3);
    }

    /**
     * Recalcule les positions 10, 20, 30… selon l'ordre courant.
     */
    public function normalizePositions(QualityControlTemplateVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $lockedVersion = $this->lockVersion($version);
            $this->assertDraft($lockedVersion);

            $points = $lockedVersion->points()
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $positions = [];

            foreach ($points as $index => $point) {
                $positions[(int) $point->getKey()] = ($index + 1) * 10;
            }

            $this->writePositions($points, $positions);
        }, attempts: 3);
    }

    /**
     * Écrit les positions en deux passes.
     *
     * L'index unique (version, position) interdit les doublons transitoires :
     * une permutation directe (10↔20) échouerait. On déplace d'abord tous les
     * points vers une plage temporaire, puis on applique les positions finales.
     *
     * @param  Collection<int, QualityControlPoint>  $points
     * @param  array<int, int>  $positions
     */
    protected function writePositions($points, array $positions): void
    {
        $temporaryBase = 1000000;

        foreach ($points->values() as $index => $point) {
            $temporary = $temporaryBase + $index;

            if ((int) $point->position !== $temporary) {
                $point->position = $temporary;
                $point->save();
            }
        }

        foreach ($points as $point) {
            $position = $positions[(int) $point->getKey()] ?? null;

            if ($position === null) {
                continue;
            }

            if ((int) $point->position !== $position) {
                $point->position = $position;
                $point->save();
            }
        }
    }

    public function nextPosition(QualityControlTemplateVersion $version): int
    {
        return ((int) $version->points()->max('position')) + 10;
    }

    /**
     * Erreurs de cohérence d'un point persisté.
     *
     * Utilisé par la validation d'activation afin de rejouer les règles sur
     * l'ensemble des points d'une version.
     *
     * @return array<int, string>
     */
    public function configurationErrors(QualityControlPoint $point): array
    {
        $errors = [];
        $type = $point->result_type;

        if ($type === QualityControlResultType::Numeric) {
            if ($point->unit === null || trim((string) $point->unit) === '') {
                $errors[] = "Le point « {$point->label} » doit définir une unité de mesure.";
            }

            if ($point->tolerance_min !== null && $point->tolerance_max !== null
                && (float) $point->tolerance_min > (float) $point->tolerance_max) {
                $errors[] = "La tolérance minimale du point « {$point->label} » dépasse sa tolérance maximale.";
            }
        } else {
            if ($point->tolerance_min !== null || $point->tolerance_max !== null) {
                $errors[] = "Le point « {$point->label} » ne peut définir des tolérances que pour une mesure numérique.";
            }
        }

        if ($type === QualityControlResultType::Choice) {
            $options = $point->options ?? [];

            if ($options === []) {
                $errors[] = "Le point « {$point->label} » doit proposer au moins une option.";
            } elseif (! $this->hasConformantOption($options)) {
                $errors[] = "Le point « {$point->label} » doit proposer au moins une option conforme.";
            }
        }

        try {
            $this->sampling->assertValidPointConfiguration(
                $point->sampling_mode,
                $point->sampling_every,
                (int) $point->required_results,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $errors[] = "Point « {$point->label} » : {$message}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return Validator::make($data, [
            'label' => [...$required, 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'result_type' => [...$required, Rule::enum(QualityControlResultType::class)],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tolerance_min' => ['sometimes', 'nullable', 'numeric'],
            'tolerance_max' => ['sometimes', 'nullable', 'numeric'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'is_critical' => ['sometimes', 'boolean'],
            'allow_deviation' => ['sometimes', 'boolean'],
            'sampling_mode' => [...$required, Rule::enum(QualityControlSamplingMode::class)],
            'sampling_every' => ['sometimes', 'nullable', 'integer', 'min:2'],
            'required_results' => ['sometimes', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'options' => ['sometimes', 'nullable', 'array', 'min:1'],
            'options.*.value' => ['required', 'string', 'max:100'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'options.*.conformant' => ['required', 'boolean'],
        ])->validate();
    }

    /**
     * Applique les règles métier de cohérence sur le point déjà rempli.
     *
     * @throws ValidationException
     */
    protected function applyBusinessRules(QualityControlPoint $point): void
    {
        $type = $point->result_type;
        $mode = $point->sampling_mode;

        if ($type === QualityControlResultType::Numeric) {
            if ($point->unit === null || trim((string) $point->unit) === '') {
                $this->fail('unit', 'Une mesure numérique doit définir une unité.');
            }

            if ($point->tolerance_min !== null && $point->tolerance_max !== null
                && (float) $point->tolerance_min > (float) $point->tolerance_max) {
                $this->fail('tolerance_min', 'La tolérance minimale ne peut pas dépasser la tolérance maximale.');
            }
        } else {
            $point->tolerance_min = null;
            $point->tolerance_max = null;
            $point->unit = null;
        }

        if ($type === QualityControlResultType::Choice) {
            $options = $point->options ?? [];

            if ($options === []) {
                $this->fail('options', 'Une liste de choix doit contenir au moins une option.');
            }

            if (! $this->hasConformantOption($options)) {
                $this->fail('options', 'Une liste de choix doit contenir au moins une option conforme.');
            }

            $values = array_map(
                fn (array $option): string => (string) ($option['value'] ?? ''),
                $options,
            );

            if (count($values) !== count(array_unique($values))) {
                $this->fail('options', 'Les valeurs des options doivent être uniques.');
            }
        } else {
            $point->options = null;
        }

        // Le nombre de résultats de référence n'est saisi que pour le mode
        // « nombre déterminé » ; en modes all et every_n il est calculé à la
        // génération du plan qualité de l'OF. La normalisation précède la
        // validation pour que les valeurs par défaut ne soient pas vues comme
        // une configuration invalide.
        if ($mode === QualityControlSamplingMode::All) {
            $point->sampling_every = null;
            $point->required_results = 1;
        } elseif ($mode === QualityControlSamplingMode::EveryN) {
            $point->required_results = 1;
        } else {
            $point->sampling_every = null;
        }

        $this->sampling->assertValidPointConfiguration(
            $mode,
            $point->sampling_every,
            (int) $point->required_results,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    protected function hasConformantOption(array $options): bool
    {
        foreach ($options as $option) {
            if ((bool) ($option['conformant'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    protected function assertPositionAvailable(
        QualityControlTemplateVersion $version,
        int $position,
        ?int $ignorePointId = null,
    ): void {
        if ($position <= 0) {
            $this->fail('position', 'La position doit être strictement positive.');
        }

        $query = $version->points()->where('position', $position);

        if ($ignorePointId !== null) {
            $query->whereKeyNot($ignorePointId);
        }

        if ($query->exists()) {
            $this->fail('position', "La position {$position} est déjà utilisée dans cette version du modèle.");
        }
    }

    protected function assertDraft(QualityControlTemplateVersion $version): void
    {
        if (! $version->isDraft()) {
            $this->fail(
                'version',
                'Seule une version brouillon peut être modifiée. Créez une nouvelle version du modèle.',
            );
        }
    }

    protected function pointReference(
        QualityControlTemplateVersion $version,
        QualityControlPoint $point,
    ): string {
        $template = $version->template()->first();

        if ($template === null) {
            return Str::limit((string) $point->label, 80, '');
        }

        return Str::limit(
            $this->audit->versionReference($template, $version).' / '.$point->label,
            80,
            '',
        );
    }

    protected function lockVersion(QualityControlTemplateVersion $version): QualityControlTemplateVersion
    {
        $locked = QualityControlTemplateVersion::query()->lockForUpdate()->find($version->getKey());

        if ($locked === null) {
            $this->fail('version', 'Cette version du modèle n\'existe plus.');
        }

        return $locked;
    }

    protected function lockVersionForPoint(QualityControlPoint $point): QualityControlTemplateVersion
    {
        $version = $point->version()->first();

        if ($version === null) {
            $this->fail('version', 'Cette version du modèle n\'existe plus.');
        }

        return $this->lockVersion($version);
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
