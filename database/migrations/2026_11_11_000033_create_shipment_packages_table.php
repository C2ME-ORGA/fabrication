<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_packages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();

            $table->string('code', 50);
            $table->string('label', 150)->nullable();
            $table->string('package_type', 30)->default('pallet');
            $table->unsignedInteger('position')->default(0);
            $table->decimal('weight_kg', 12, 3)->nullable();
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->string('seal_number', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['shipment_id', 'code']);
            $table->index(['shipment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_packages');
    }
};
