<?php

namespace App\Models;

use App\Enums\QualityControlTemplateVersionStatus;
use App\Enums\QualityControlType;
use Database\Factories\QualityControlTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle de contrôle qualité réutilisable.
 *
 * L'identité du modèle (code, nom, type) est stable. Son contenu métier est
 * porté par des versions : une seule version est active à la fois, et elle
 * est immuable. Modifier un modèle actif impose donc la création d'une
 * nouvelle version brouillon (copy-on-write), ce qui garantit que les plans
 * qualité déjà générés ne changent jamais.
 */
#[Fillable(['code', 'name', 'description', 'control_type'])]
class QualityControlTemplate extends Model
{
    /** @use HasFactory<QualityControlTemplateFactory> */
    use HasFactory;

    /**
     * Champs techniques écrits uniquement par les services métier.
     */
    public const PROTECTED_FIELDS = [
        'is_active',
        'current_version_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'control_type' => QualityControlType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<QualityControlTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QualityControlTemplateVersion::class)
            ->orderByDesc('version_number')
            ->orderByDesc('id');
    }

    /**
     * Version actuellement active, si elle existe.
     *
     * @return BelongsTo<QualityControlTemplateVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(QualityControlTemplateVersion::class, 'current_version_id');
    }

    /**
     * Exigences de gamme référençant ce modèle.
     *
     * @return HasMany<WorkshopRoutingOperationQualityRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(WorkshopRoutingOperationQualityRequirement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function activeVersion(): ?QualityControlTemplateVersion
    {
        return $this->versions()
            ->where('status', QualityControlTemplateVersionStatus::Active->value)
            ->first();
    }

    public function draftVersion(): ?QualityControlTemplateVersion
    {
        return $this->versions()
            ->where('status', QualityControlTemplateVersionStatus::Draft->value)
            ->first();
    }

    /**
     * Le modèle est-il référencé par au moins une exigence de gamme ?
     */
    public function isUsedByRoutings(): bool
    {
        return $this->requirements()->exists();
    }

    /**
     * Le modèle a-t-il déjà été copié dans un plan qualité d'OF ?
     *
     * Le plan qualité est livré au Lot 3 ; cette méthode sera étendue à ce
     * moment-là pour consulter work_order_quality_controls.
     */
    public function hasGeneratedControls(): bool
    {
        return false;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function nextVersionNumber(): int
    {
        return ((int) $this->versions()->max('version_number')) + 1;
    }
}
