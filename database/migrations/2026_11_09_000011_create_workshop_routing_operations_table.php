<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_routing_operations', function (Blueprint $table): void {
            $table->id();

            // Les opérations n'existent que dans leur gamme modèle.
            $table->foreignId('workshop_routing_id')
                ->constrained('workshop_routings')
                ->cascadeOnDelete();

            $table->string('code', 50);
            $table->string('name');
            $table->text('instructions')->nullable();

            // Ordre d'exécution indicatif. Les dépendances restent la seule
            // règle d'enchaînement : la position sert à l'affichage.
            $table->unsignedInteger('position')->default(0);

            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->unique(['workshop_routing_id', 'code']);
            $table->index(['workshop_routing_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_routing_operations');
    }
};
