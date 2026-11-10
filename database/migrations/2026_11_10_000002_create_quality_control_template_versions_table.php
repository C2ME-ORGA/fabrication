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
                ->constrained('quality_control_templates', 'id', 'qct_versions_template_foreign')
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

            // Une seule version active par modèle. MySQL 8 refuse une colonne
            // générée qui référence une colonne portant une clé étrangère :
            // la valeur est donc maintenue par le service, sous verrou, et
            // protégée par l'index unique ci-dessous.
            $table->unsignedBigInteger('active_template_id')->nullable();

            $table->unique('active_template_id', 'quality_template_versions_active_unique');
        });

        // Référence circulaire : la FK ne peut être déclarée qu'une fois la
        // table des versions créée (même pattern que documents.current_version_id).
        Schema::table('quality_control_templates', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'qct_templates_current_version_foreign')
                ->references('id')
                ->on('quality_control_template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quality_control_templates', function (Blueprint $table): void {
            $table->dropForeign('qct_templates_current_version_foreign');
        });

        Schema::dropIfExists('quality_control_template_versions');
    }
};
