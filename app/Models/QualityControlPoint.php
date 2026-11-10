<?php

namespace App\Models;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use Database\Factories\QualityControlPointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Point de contrôle d'une version de modèle.
 *
 * Un point n'est modifiable que tant que sa version est brouillon. La garde
 * est appliquée au niveau du modèle : une version active ou retirée est
 * immuable, ce qui protège l'historique des plans qualité déjà générés.
 */
#[Fillable([
    'label',
    'instructions',
    'result_type',
    'unit',
    'tolerance_min',
    'tolerance_max',
    'is_mandatory',
    'is_critical',
    'allow_deviation',
    'sampling_mode',
    'sampling_every',
    'required_results',
    'options',
])]
class QualityControlPoint extends Model
{
    /** @use HasFactory<QualityControlPointFactory> */
    use HasFactory;

    /**
     * Champs techniques écrits uniquement par les services métier.
     */
    public const PROTECTED_FIELDS = [
        'quality_control_template_version_id',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'result_type' => QualityControlResultType::class,
            'tolerance_min' => 'decimal:4',
            'tolerance_max' => 'decimal:4',
            'is_mandatory' => 'boolean',
            'is_critical' => 'boolean',
            'allow_deviation' => 'boolean',
            'sampling_mode' => QualityControlSamplingMode::class,
            'sampling_every' => 'integer',
            'required_results' => 'integer',
            'options' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QualityControlTemplateVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QualityControlTemplateVersion::class, 'quality_control_template_version_id');
    }

    /**
     * Une version active ou retirée est immuable.
     */
    public function isEditable(): bool
    {
        $version = $this->relationLoaded('version')
            ? $this->version
            : $this->version()->first();

        return $version?->isEditable() ?? false;
    }

    protected function performUpdate(Builder $query): void
    {
        $this->assertVersionIsDraft('modifiés');

        parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        $this->assertVersionIsDraft('supprimés');

        parent::performDeleteOnModel();
    }

    protected function assertVersionIsDraft(string $action): void
    {
        if (! $this->isEditable()) {
            throw new RuntimeException(
                "Les points de contrôle d'une version active ou retirée ne peuvent pas être {$action}."
            );
        }
    }
}
