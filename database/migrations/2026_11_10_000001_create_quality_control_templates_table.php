<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_control_templates', function (Blueprint $table): void {
            $table->id();

            // Le code est l'identité métier du modèle. Il est normalisé en
            // majuscules par le service avant écriture.
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('control_type', 30)->index();

            // Reflète l'existence d'une version active. Écrit uniquement par
            // le service de versionnement.
            $table->boolean('is_active')->default(false)->index();

            // FK ajoutée dans la migration des versions (référence circulaire).
            $table->unsignedBigInteger('current_version_id')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_control_templates');
    }
};
