<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_quality_controls', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->cascadeOnDelete();

            // Cible opérationnelle du contrôle. La colonne est nullable car un
            // contrôle transverse peut survivre à la suppression de l'opération.
            $table->foreignId('work_order_operation_id')
                ->nullable()
                ->constrained('work_order_operations')
                ->nullOnDelete();

            // Renseigné uniquement pour un contrôle ciblé sur un repère unique.
            $table->foreignId('work_order_item_id')
                ->nullable()
                ->constrained('work_order_items')
                ->nullOnDelete();

            // Exigence de gamme à l'origine de la copie. NULL pour un ajout manuel.
            $table->foreignId('source_requirement_id')
                ->nullable()
                ->constrained('workshop_routing_operation_quality_requirements')
                ->nullOnDelete();

            $table->string('source_type', 20)->default('routing');

            // Photographie du modèle au moment de la génération : toute
            // évolution ultérieure du référentiel est sans effet sur ce contrôle.
            $table->string('template_code', 50);
            $table->string('template_name', 255);
            $table->unsignedInteger('template_version_number');
            $table->string('control_type', 30);
            $table->string('scope', 20);
            $table->string('trigger', 20);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('allow_deviation')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->string('status', 20)->default('pending');
            $table->timestamp('required_by_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('snapshot_metadata')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'status'], 'wo_quality_controls_status_index');
            $table->index('work_order_operation_id', 'wo_quality_controls_operation_index');
            $table->index('work_order_item_id', 'wo_quality_controls_item_index');

            // Anti-doublon portable SQLite/MySQL : les ajouts manuels
            // (source_requirement_id NULL) restent libres, mais une exigence
            // ne peut générer qu'un seul contrôle par OF. SQLite et MySQL
            // autorisent plusieurs NULL dans un index unique.
            $table->unique(
                ['work_order_id', 'source_requirement_id'],
                'wo_quality_controls_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_quality_controls');
    }
};
