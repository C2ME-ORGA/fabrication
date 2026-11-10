<?php

namespace App\Services;

use App\Enums\QualityAuditEvent;
use App\Enums\QualityAuditSubject;
use App\Enums\QualityControlType;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée unique des mutations d'un modèle de contrôle qualité.
 *
 * Un modèle est créé inactif avec une première version brouillon. Il devient
 * actif uniquement par l'activation d'une version valide. La suppression
 * physique n'est possible que si le modèle n'a jamais été référencé.
 */
class QualityControlTemplateService
{
    public function __construct(
        protected QualityControlTemplateVersionService $versions,
        protected QualityAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): QualityControlTemplate
    {
        $this->assertNoProtectedFields($data);
        $validated = $this->validate($data);

        try {
            return DB::transaction(function () use ($validated, $actor): QualityControlTemplate {
                $template = new QualityControlTemplate;
                $template->fill(Arr::only($validated, ['code', 'name', 'description', 'control_type']));
                $template->is_active = false;
                $template->created_by = $actor?->getKey() ?? Auth::id();
                $template->save();

                $version = $this->versions->createDraft($template, $actor, 'Version initiale.');

                $this->audit->record(
                    QualityAuditEvent::TemplateCreated,
                    QualityAuditSubject::Template,
                    (int) $template->getKey(),
                    $this->audit->templateReference($template),
                    $actor,
                    null,
                    null,
                    null,
                    'Création du modèle de contrôle.',
                    [
                        'control_type' => $template->control_type->value,
                        'initial_version_id' => $version->getKey(),
                        'initial_version_number' => $version->version_number,
                    ],
                );

                return $template->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('code', 'Ce code de modèle de contrôle est déjà utilisé.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        QualityControlTemplate $template,
        array $data,
        ?User $actor = null,
    ): QualityControlTemplate {
        $this->assertNoProtectedFields($data);
        $validated = $this->validate($data, partial: true, ignoreId: (int) $template->getKey());

        try {
            return DB::transaction(function () use ($template, $validated, $actor): QualityControlTemplate {
                $locked = $this->lock($template);

                $previous = [];
                foreach (array_keys($validated) as $field) {
                    $previous[$field] = $locked->getAttribute($field);
                }

                $locked->fill(Arr::only($validated, ['code', 'name', 'description', 'control_type']));
                $changed = array_keys($locked->getDirty());

                if ($changed === []) {
                    return $locked->refresh();
                }

                $locked->save();

                $this->audit->record(
                    QualityAuditEvent::TemplateUpdated,
                    QualityAuditSubject::Template,
                    (int) $locked->getKey(),
                    $this->audit->templateReference($locked),
                    $actor,
                    null,
                    null,
                    null,
                    'Modification du modèle de contrôle.',
                    [
                        'changed_fields' => $changed,
                        'previous' => Arr::only($previous, $changed),
                        'current' => Arr::only($locked->getAttributes(), $changed),
                    ],
                );

                return $locked->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('code', 'Ce code de modèle de contrôle est déjà utilisé.');
        }
    }

    /**
     * Active une version du modèle.
     */
    public function activate(
        QualityControlTemplate $template,
        QualityControlTemplateVersion $version,
        ?User $actor = null,
        ?string $changeNote = null,
    ): QualityControlTemplateVersion {
        if ((int) $version->quality_control_template_id !== (int) $template->getKey()) {
            $this->fail('version', 'Cette version n\'appartient pas à ce modèle de contrôle.');
        }

        return $this->versions->activate($version, $actor, $changeNote);
    }

    public function deactivate(QualityControlTemplate $template, ?User $actor = null): QualityControlTemplate
    {
        return $this->versions->deactivate($template, $actor);
    }

    public function startNewVersion(
        QualityControlTemplate $template,
        ?User $actor = null,
        ?string $changeNote = null,
    ): QualityControlTemplateVersion {
        return $this->versions->createFromActive($template, $actor, $changeNote);
    }

    /**
     * Suppression physique réservée aux modèles jamais référencés.
     */
    public function delete(QualityControlTemplate $template, ?User $actor = null): void
    {
        DB::transaction(function () use ($template, $actor): void {
            $locked = $this->lock($template);

            if ($locked->isUsedByRoutings()) {
                $this->fail(
                    'template',
                    'Ce modèle est référencé par une gamme atelier et ne peut pas être supprimé. Désactivez-le.',
                );
            }

            if ($locked->hasGeneratedControls()) {
                $this->fail(
                    'template',
                    'Ce modèle a déjà servi à générer des contrôles et ne peut pas être supprimé. Désactivez-le.',
                );
            }

            $this->audit->record(
                QualityAuditEvent::TemplateDeleted,
                QualityAuditSubject::Template,
                (int) $locked->getKey(),
                $this->audit->templateReference($locked),
                $actor,
                null,
                $locked->is_active ? 'active' : 'inactive',
                null,
                'Suppression du modèle de contrôle.',
                ['versions' => $locked->versions()->count()],
            );

            $locked->delete();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validate(array $data, bool $partial = false, ?int $ignoreId = null): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        $uniqueCode = Rule::unique('quality_control_templates', 'code');

        if ($ignoreId !== null) {
            $uniqueCode->ignore($ignoreId);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->normalizeCode((string) $data['code']);
        }

        return Validator::make($data, [
            'code' => [...$required, 'string', 'max:50', $uniqueCode],
            'name' => [...$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'control_type' => [...$required, Rule::enum(QualityControlType::class)],
        ])->validate();
    }

    protected function normalizeCode(string $code): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($code)) ?? '';
        $normalized = mb_strtoupper($normalized);

        if ($normalized === '') {
            $this->fail('code', 'Le code du modèle de contrôle est obligatoire.');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNoProtectedFields(array $data): void
    {
        $forbidden = array_values(array_filter(
            QualityControlTemplate::PROTECTED_FIELDS,
            fn (string $field): bool => array_key_exists($field, $data),
        ));

        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'template' => 'Champ(s) non modifiable(s) directement : '.implode(', ', $forbidden).'.',
            ]);
        }
    }

    protected function lock(QualityControlTemplate $template): QualityControlTemplate
    {
        $locked = QualityControlTemplate::query()->lockForUpdate()->find($template->getKey());

        if ($locked === null) {
            $this->fail('template', 'Ce modèle de contrôle n\'existe plus.');
        }

        return $locked;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
