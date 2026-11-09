<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_control_points', function (Blueprint $table): void {
            $table->id();

            // Les points appartiennent à une version précise du modèle : une
            // version active est immuable (garde applicative dans le modèle).
            $table->foreignId('quality_control_template_version_id')
                ->constrained('quality_control_template_versions')
                ->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('label');
            $table->text('instructions')->nullable();

            $table->string('result_type', 30);
            $table->string('unit', 20)->nullable();
            $table->decimal('tolerance_min', 14, 4)->nullable();
            $table->decimal('tolerance_max', 14, 4)->nullable();

            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->boolean('allow_deviation')->default(false);

            // Échantillonnage : all, every_n, fixed. required_results est la
            // valeur de référence au niveau du modèle ; elle est recalculée à
            // la génération du plan qualité pour all et every_n.
            $table->string('sampling_mode', 20)->default('all');
            $table->unsignedInteger('sampling_every')->nullable();
            $table->unsignedInteger('required_results')->default(1);

            // Liste de choix : [{"value": "...", "label": "...", "conformant": true}]
            $table->json('options')->nullable();

            $table->timestamps();

            $table->unique(
                ['quality_control_template_version_id', 'position'],
                'quality_control_points_position_unique',
            );
            $table->index(
                ['quality_control_template_version_id', 'result_type'],
                'quality_control_points_version_result_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_control_points');
    }
};
