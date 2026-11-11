<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_package_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shipment_package_id')
                ->constrained('shipment_packages')
                ->cascadeOnDelete();

            $table->foreignId('shipment_line_id')
                ->constrained('shipment_lines')
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            $table->timestamps();

            $table->unique(
                ['shipment_package_id', 'shipment_line_id'],
                'shipment_package_line_unique',
            );
            $table->index('shipment_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_package_lines');
    }
};
