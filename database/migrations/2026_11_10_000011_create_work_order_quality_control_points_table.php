<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_quality_control_points', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_quality_control_id')
                ->constrained('work_order_quality_controls', 'id', 'wo_qc_points_control_foreign')
                ->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('label', 255);
            $table->text('instructions')->nullable();

            $table->string('result_type', 30);
            $table->string('unit', 20)->nullable();
            $table->decimal('tolerance_min', 14, 4)->nullable();
            $table->decimal('tolerance_max', 14, 4)->nullable();

            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->boolean('allow_deviation')->default(false);

            $table->string('sampling_mode', 20)->default('all');
            $table->unsignedInteger('sampling_every')->nullable();

            // Nombre de résultats validés attendus, calculé et figé à la
            // génération pour all/every_n, saisi dans le modèle pour fixed.
            $table->unsignedInteger('required_results')->default(1);

            // Sélection déterministe des repères pour every_n (et liste
            // complète pour all), persistée pour garantir la reproductibilité.
            $table->json('sampled_item_ids')->nullable();

            $table->json('options')->nullable();
            $table->timestamps();

            $table->unique(
                ['work_order_quality_control_id', 'position'],
                'wo_quality_control_points_position_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_quality_control_points');
    }
};
