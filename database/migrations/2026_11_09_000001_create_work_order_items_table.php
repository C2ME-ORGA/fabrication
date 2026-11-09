<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_items', function (Blueprint $table): void {
            $table->id();

            // Les pièces appartiennent à l'OF. La suppression d'un brouillon
            // supprime ses pièces ; les OF non brouillons ne sont jamais supprimés.
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();

            $table->string('mark', 100);
            $table->string('designation');
            $table->string('profile', 100)->nullable();
            $table->string('steel_grade', 100)->nullable();
            $table->decimal('length_mm', 12, 2)->nullable();

            $table->unsignedInteger('quantity_planned')->default(0);
            $table->unsignedInteger('quantity_produced')->default(0);
            $table->unsignedInteger('quantity_rejected')->default(0);

            $table->decimal('unit_weight_kg', 12, 3)->nullable();
            $table->decimal('total_weight_kg', 14, 3)->nullable();

            $table->string('operation_group', 100)->nullable();
            $table->string('status', 20)->default('todo');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['work_order_id', 'mark']);
            $table->index(['work_order_id', 'status']);
            $table->index('mark');
            $table->index('operation_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
    }
};
