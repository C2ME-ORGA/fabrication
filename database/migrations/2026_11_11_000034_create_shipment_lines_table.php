<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();

            $table->foreignId('work_order_item_id')
                ->constrained('work_order_items')
                ->restrictOnDelete();

            $table->string('mark_snapshot', 100);
            $table->string('designation_snapshot', 255);
            $table->unsignedInteger('quantity_prepared')->default(0);
            $table->unsignedInteger('quantity_shipped')->default(0);
            $table->decimal('unit_weight_kg_snapshot', 12, 3)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['shipment_id', 'work_order_item_id']);
            $table->index('work_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
    }
};
