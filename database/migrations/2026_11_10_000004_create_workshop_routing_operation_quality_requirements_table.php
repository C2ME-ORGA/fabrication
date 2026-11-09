<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_routing_operation_quality_requirements', function (Blueprint $table): void {
            $table->id();

            // L'exigence appartient à une opération de gamme modèle.
            $table->foreignId('workshop_routing_operation_id')
                ->constrained('workshop_routing_operations')
                ->cascadeOnDelete();

            // Un modèle référencé par une gamme ne peut pas être supprimé :
            // il doit être désactivé (même logique que les gammes).
            $table->foreignId('quality_control_template_id')
                ->constrained('quality_control_templates')
                ->restrictOnDelete();

            // operation : constat process ; item : contrôle par repère ;
            // work_order : contrôle transverse (final, documentaire).
            $table->string('scope', 20)->default('operation');
            $table->string('trigger', 20)->default('on_completion');
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(
                ['workshop_routing_operation_id', 'quality_control_template_id'],
                'routing_operation_quality_template_unique',
            );
            $table->index('quality_control_template_id', 'routing_quality_requirement_template_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_routing_operation_quality_requirements');
    }
};
