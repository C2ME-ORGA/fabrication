<?php

namespace App\Models;

use App\Enums\QualityControlTemplateVersionStatus;
use Database\Factories\QualityControlTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Version d'un modèle de contrôle qualité.
 *
 * Une version est le seul support des points de contrôle. Elle est
 * immuable dès qu'elle est active ou retirée : toute évolution passe par
 * une nouvelle version brouillon créée par copie (copy-on-write).
 */
#[Fillable(['change_note'])]
class QualityControlTemplateVersion extends Model
{
    /** @use HasFactory<QualityControlTemplateVersionFactory> */
    use HasFactory;

    /**
     * Champs techniques écrits uniquement par les services métier.
     */
    public const PROTECTED_FIELDS = [
        'quality_control_template_id',
        'version_number',
        'status',
        'activated_by',
        'activated_at',
        'retired_at',
        'created_by',
        'active_template_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'status' => QualityControlTemplateVersionStatus::class,
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QualityControlTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QualityControlTemplate::class, 'quality_control_template_id');
    }

    /**
     * @return HasMany<QualityControlPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(QualityControlPoint::class, 'quality_control_template_version_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === QualityControlTemplateVersionStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === QualityControlTemplateVersionStatus::Active;
    }

    public function isRetired(): bool
    {
        return $this->status === QualityControlTemplateVersionStatus::Retired;
    }

    /**
     * Seule une version brouillon accepte des modifications.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Nombre de points obligatoires.
     */
    public function mandatoryPointsCount(): int
    {
        return $this->points()->where('is_mandatory', true)->count();
    }

    public function scopeDraft(Builder $query): void
    {
        $query->where('status', QualityControlTemplateVersionStatus::Draft->value);
    }

    public function scopeActiveVersion(Builder $query): void
    {
        $query->where('status', QualityControlTemplateVersionStatus::Active->value);
    }
}
