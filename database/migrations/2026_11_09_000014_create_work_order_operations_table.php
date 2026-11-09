<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_operations', function (Blueprint $table): void {
            $table->id();

            // Les opérations appartiennent à l'OF. La suppression d'un
            // brouillon supprime ses opérations ; les OF libérés ne sont
            // jamais supprimés.
            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->cascadeOnDelete();

            // Traçabilité de la copie. La suppression de la gamme modèle ne
            // doit pas altérer les opérations déjà générées.
            $table->foreignId('source_routing_operation_id')->nullable()
                ->constrained('workshop_routing_operations')
                ->nullOnDelete();

            $table->string('code', 50);
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->boolean('is_mandatory')->default(true);

            $table->string('status', 20)->default('pending');
            $table->text('blocked_reason')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('blocked_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'code']);
            $table->index(['work_order_id', 'position']);
            $table->index(['work_order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_operations');
    }
};
