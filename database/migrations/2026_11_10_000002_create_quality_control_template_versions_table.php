<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_control_template_versions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('quality_control_template_id')
                ->constrained('quality_control_templates')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft')->index();

            // Obligatoire à l'activation d'une version ultérieure à la première.
            $table->text('change_note')->nullable();

            $table->foreignId('activated_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['quality_control_template_id', 'version_number'],
                'quality_template_versions_number_unique',
            );

            // Une seule version active par modèle, garantie de façon portable
            // sur SQLite comme sur MySQL : la colonne générée vaut l'identifiant
            // du modèle uniquement pour la version active, NULL sinon. L'index
            // unique ignore alors les versions brouillon et retirées.
            $table->unsignedBigInteger('active_template_id')->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN quality_control_template_id ELSE NULL END");

            $table->unique('active_template_id', 'quality_template_versions_active_unique');
        });

        // Référence circulaire : la FK ne peut être déclarée qu'une fois la
        // table des versions créée (même pattern que documents.current_version_id).
        Schema::table('quality_control_templates', function (Blueprint $table): void {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('quality_control_template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quality_control_templates', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('quality_control_template_versions');
    }
};
