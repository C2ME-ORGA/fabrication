<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_routing_operation_dependencies', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('workshop_routing_operation_id')
                ->constrained('workshop_routing_operations', 'id', 'ws_routing_dep_operation_fk')
                ->cascadeOnDelete();

            // Opération qui doit être terminée avant que la première puisse
            // démarrer. La validation croisée (même gamme, absence de cycle)
            // est assurée par le service métier.
            $table->foreignId('depends_on_operation_id')
                ->constrained('workshop_routing_operations', 'id', 'ws_routing_dep_target_fk')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['workshop_routing_operation_id', 'depends_on_operation_id'],
                'workshop_routing_dependencies_unique',
            );
            $table->index('depends_on_operation_id', 'workshop_routing_dependencies_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_routing_operation_dependencies');
    }
};
