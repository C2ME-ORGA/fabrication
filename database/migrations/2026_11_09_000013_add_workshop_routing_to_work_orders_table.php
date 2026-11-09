<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            // Gamme modèle utilisée pour générer les opérations de l'OF.
            // La suppression d'une gamme ne doit jamais effacer les opérations
            // déjà copiées : la référence devient nulle, les copies subsistent.
            $table->foreignId('workshop_routing_id')->nullable()
                ->after('responsible_id')
                ->constrained('workshop_routings')
                ->nullOnDelete();

            $table->index('workshop_routing_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['workshop_routing_id']);
            $table->dropIndex(['workshop_routing_id']);
            $table->dropColumn('workshop_routing_id');
        });
    }
};
