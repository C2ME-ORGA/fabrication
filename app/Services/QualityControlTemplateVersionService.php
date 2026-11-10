<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlTemplateVersionStatus;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie des versions d'un modèle de contrôle.
 *
 * Une version active est immuable. Toute évolution passe par une nouvelle
 * version brouillon créée par copie de la version active (copy-on-write).
 * L'activation est transactionnelle et garantit qu'une seule version reste
 * active par modèle (valeur maintenue sous verrou + index unique).
 */
class QualityControlTemplateVersionService
{
    public function __construct(
        protected QualityControlTemplateValidationService $validation,
        protected QualityControlPointService $points,
        protected QualityAuditService $audit,
    ) {}

    /**
     * Crée une version brouillon vide (première version d'un modèle).
     */
    public function createDraft(
        QualityControlTemplate $template,
        ?User $actor = null,
        ?string $changeNote = null,
    ): QualityControlTemplateVersion {
        return DB::transaction(function () use ($template, $actor, $changeNote): QualityControlTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertNoDraft($lockedTemplate);

            $version = $this->newVersion($lockedTemplate, $changeNote, $actor);

            $this->audit->record(
                QualityAuditEvent::TemplateVersionCreated,
                QualityAuditSubject::TemplateVersion,
                (int) $version->getKey(),
                $this->audit->versionReference($lockedTemplate, $version),
                $actor,
                null,
                null,
                QualityControlTemplateVersionStatus::Draft->value,
                'Création de la version brouillon.',
                ['version_number' => $version->version_number],
            );

            return $version;
        }, attempts: 3);
    }

    /**
     * Crée une version brouillon par copie de la version active.
     */
    public function createFromActive(
        QualityControlTemplate $template,
        ?User $actor = null,
        ?string $changeNote = null,
    ): QualityControlTemplateVersion {
        return DB::transaction(function () use ($template, $actor, $changeNote): QualityControlTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertNoDraft($lockedTemplate);

            $activeVersion = $lockedTemplate->versions()
                ->where('status', QualityControlTemplateVersionStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($activeVersion === null) {
                throw ValidationException::withMessages([
                    'version' => 'Aucune version active à copier pour ce modèle.',
                ]);
            }

            $version = $this->newVersion($lockedTemplate, $changeNote, $actor);

            foreach ($activeVersion->points()->orderBy('position')->get() as $sourcePoint) {
                $copy = new QualityControlPoint;
                $copy->fill([
                    'label' => $sourcePoint->label,
                    'instructions' => $sourcePoint->instructions,
                    'result_type' => $sourcePoint->result_type,
                    'unit' => $sourcePoint->unit,
                    'tolerance_min' => $sourcePoint->tolerance_min,
                    'tolerance_max' => $sourcePoint->tolerance_max,
                    'is_mandatory' => $sourcePoint->is_mandatory,
                    'is_critical' => $sourcePoint->is_critical,
                    'allow_deviation' => $sourcePoint->allow_deviation,
                    'sampling_mode' => $sourcePoint->sampling_mode,
                    'sampling_every' => $sourcePoint->sampling_every,
                    'required_results' => $sourcePoint->required_results,
                    'options' => $sourcePoint->options,
                ]);
                $copy->quality_control_template_version_id = $version->getKey();
                $copy->position = $sourcePoint->position;
                $copy->save();
            }

            $this->audit->record(
                QualityAuditEvent::TemplateVersionCreated,
                QualityAuditSubject::TemplateVersion,
                (int) $version->getKey(),
                $this->audit->versionReference($lockedTemplate, $version),
                $actor,
                null,
                QualityControlTemplateVersionStatus::Active->value,
                QualityControlTemplateVersionStatus::Draft->value,
                'Nouvelle version par copie de la version active.',
                [
                    'version_number' => $version->version_number,
                    'copied_from_version_id' => $activeVersion->getKey(),
                    'copied_points' => $activeVersion->points()->count(),
                ],
            );

            return $version->refresh();
        }, attempts: 3);
    }

    /**
     * Active une version brouillon et retire l'éventuelle version active.
     */
    public function activate(
        QualityControlTemplateVersion $version,
        ?User $actor = null,
        ?string $changeNote = null,
    ): QualityControlTemplateVersion {
        return DB::transaction(function () use ($version, $actor, $changeNote): QualityControlTemplateVersion {
            $lockedVersion = $this->lockVersion($version);
            $lockedTemplate = $this->lockTemplate($lockedVersion->template);

            if (! $lockedVersion->isDraft()) {
                throw ValidationException::withMessages([
                    'version' => 'Seule une version brouillon peut être activée.',
                ]);
            }

            if ($changeNote !== null && trim($changeNote) !== '') {
                $lockedVersion->change_note = trim($changeNote);
            }

            if ((int) $lockedVersion->version_number > 1
                && ($lockedVersion->change_note === null || trim((string) $lockedVersion->change_note) === '')) {
                throw ValidationException::withMessages([
                    'change_note' => 'Le motif de changement est obligatoire pour une version ultérieure à la première.',
                ]);
            }

            $this->validation->assertValidForActivation($lockedVersion);

            // Les positions sont normalisées avant l'activation : une fois la
            // version active, ses points sont immuables.
            $this->points->normalizePositions($lockedVersion);

            $previousActive = $lockedTemplate->versions()
                ->where('status', QualityControlTemplateVersionStatus::Active->value)
                ->whereKeyNot($lockedVersion->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($previousActive as $previous) {
                $previous->forceFill([
                    'status' => QualityControlTemplateVersionStatus::Retired,
                    'retired_at' => now(),
                    'active_template_id' => null,
                ])->save();

                $this->audit->record(
                    QualityAuditEvent::TemplateVersionRetired,
                    QualityAuditSubject::TemplateVersion,
                    (int) $previous->getKey(),
                    $this->audit->versionReference($lockedTemplate, $previous),
                    $actor,
                    null,
                    QualityControlTemplateVersionStatus::Active->value,
                    QualityControlTemplateVersionStatus::Retired->value,
                    'Version retirée au profit d\'une nouvelle version active.',
                    ['replaced_by_version_id' => $lockedVersion->getKey()],
                );
            }

            $lockedVersion->forceFill([
                'status' => QualityControlTemplateVersionStatus::Active,
                'active_template_id' => $lockedTemplate->getKey(),
                'activated_by' => $actor?->getKey(),
                'activated_at' => now(),
                'retired_at' => null,
            ])->save();

            $lockedTemplate->forceFill([
                'is_active' => true,
                'current_version_id' => $lockedVersion->getKey(),
            ])->save();

            $this->audit->record(
                QualityAuditEvent::TemplateVersionActivated,
                QualityAuditSubject::TemplateVersion,
                (int) $lockedVersion->getKey(),
                $this->audit->versionReference($lockedTemplate, $lockedVersion),
                $actor,
                null,
                QualityControlTemplateVersionStatus::Draft->value,
                QualityControlTemplateVersionStatus::Active->value,
                $lockedVersion->change_note,
                [
                    'version_number' => $lockedVersion->version_number,
                    'points' => $lockedVersion->points()->count(),
                    'mandatory_points' => $lockedVersion->mandatoryPointsCount(),
                ],
            );

            return $lockedVersion->refresh();
        }, attempts: 3);
    }

    /**
     * Désactive un modèle : la version active devient retirée.
     */
    public function deactivate(QualityControlTemplate $template, ?User $actor = null): QualityControlTemplate
    {
        return DB::transaction(function () use ($template, $actor): QualityControlTemplate {
            $lockedTemplate = $this->lockTemplate($template);

            if (! $lockedTemplate->isActive()) {
                return $lockedTemplate->refresh();
            }

            $activeVersion = $lockedTemplate->versions()
                ->where('status', QualityControlTemplateVersionStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($activeVersion !== null) {
                $activeVersion->forceFill([
                    'status' => QualityControlTemplateVersionStatus::Retired,
                    'retired_at' => now(),
                    'active_template_id' => null,
                ])->save();

                $this->audit->record(
                    QualityAuditEvent::TemplateVersionRetired,
                    QualityAuditSubject::TemplateVersion,
                    (int) $activeVersion->getKey(),
                    $this->audit->versionReference($lockedTemplate, $activeVersion),
                    $actor,
                    null,
                    QualityControlTemplateVersionStatus::Active->value,
                    QualityControlTemplateVersionStatus::Retired->value,
                    'Désactivation du modèle de contrôle.',
                );
            }

            $lockedTemplate->forceFill(['is_active' => false])->save();

            return $lockedTemplate->refresh();
        }, attempts: 3);
    }

    /**
     * Supprime une version brouillon qui n'est pas la seule du modèle.
     */
    public function deleteDraft(
        QualityControlTemplateVersion $version,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($version, $actor): void {
            $lockedVersion = $this->lockVersion($version);
            $lockedTemplate = $this->lockTemplate($lockedVersion->template);

            if (! $lockedVersion->isDraft()) {
                throw ValidationException::withMessages([
                    'version' => 'Seule une version brouillon peut être supprimée.',
                ]);
            }

            $versionCount = $lockedTemplate->versions()->count();

            if ($versionCount <= 1) {
                throw ValidationException::withMessages([
                    'version' => 'La seule version d\'un modèle ne peut pas être supprimée. Supprimez le modèle.',
                ]);
            }

            $this->audit->record(
                QualityAuditEvent::TemplateVersionDeleted,
                QualityAuditSubject::TemplateVersion,
                (int) $lockedVersion->getKey(),
                $this->audit->versionReference($lockedTemplate, $lockedVersion),
                $actor,
                null,
                QualityControlTemplateVersionStatus::Draft->value,
                null,
                'Suppression d\'une version brouillon.',
                ['version_number' => $lockedVersion->version_number],
            );

            $lockedVersion->delete();
        }, attempts: 3);
    }

    protected function newVersion(
        QualityControlTemplate $template,
        ?string $changeNote,
        ?User $actor,
    ): QualityControlTemplateVersion {
        $version = new QualityControlTemplateVersion;
        $version->quality_control_template_id = $template->getKey();
        $version->version_number = $template->nextVersionNumber();
        $version->status = QualityControlTemplateVersionStatus::Draft;
        $version->change_note = $changeNote;
        $version->created_by = $actor?->getKey();
        $version->save();

        return $version;
    }

    protected function assertNoDraft(QualityControlTemplate $template): void
    {
        if ($template->draftVersion() !== null) {
            throw ValidationException::withMessages([
                'version' => 'Une version brouillon existe déjà pour ce modèle. Complétez-la ou activez-la.',
            ]);
        }
    }

    protected function lockTemplate(QualityControlTemplate $template): QualityControlTemplate
    {
        $locked = QualityControlTemplate::query()->lockForUpdate()->find($template->getKey());

        if ($locked === null) {
            throw ValidationException::withMessages([
                'template' => 'Ce modèle de contrôle n\'existe plus.',
            ]);
        }

        return $locked;
    }

    protected function lockVersion(QualityControlTemplateVersion $version): QualityControlTemplateVersion
    {
        $locked = QualityControlTemplateVersion::query()->lockForUpdate()->find($version->getKey());

        if ($locked === null) {
            throw ValidationException::withMessages([
                'version' => 'Cette version du modèle n\'existe plus.',
            ]);
        }

        return $locked;
    }
}
