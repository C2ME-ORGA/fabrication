<?php

namespace Database\Factories;

use App\Enums\QualityControlTemplateVersionStatus;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityControlTemplateVersion>
 */
class QualityControlTemplateVersionFactory extends Factory
{
    protected $model = QualityControlTemplateVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quality_control_template_id' => QualityControlTemplate::factory(),
            'version_number' => 1,
            'status' => QualityControlTemplateVersionStatus::Draft,
            'change_note' => null,
            'activated_at' => null,
            'retired_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (QualityControlTemplateVersion $version): void {
            if ($version->status === QualityControlTemplateVersionStatus::Active
                && $version->active_template_id === null) {
                $version->forceFill([
                    'active_template_id' => $version->quality_control_template_id,
                ])->saveQuietly();
            }
        });
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => QualityControlTemplateVersionStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => QualityControlTemplateVersionStatus::Retired,
            'activated_at' => now()->subDay(),
            'retired_at' => now(),
        ]);
    }
}
