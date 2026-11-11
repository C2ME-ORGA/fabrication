<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('planned_ship_at')->nullable();
            $table->timestamp('actual_ship_at')->nullable();
            $table->string('carrier_name', 150)->nullable();
            $table->string('carrier_reference', 100)->nullable();
            $table->string('delivery_note_reference', 100)->nullable();
            $table->string('delivery_address', 255)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['work_order_id', 'status']);
            $table->index(['status', 'planned_ship_at']);
            $table->index('delivery_note_reference');
            $table->unique(['work_order_id', 'delivery_note_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
