<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_deviation_targets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('quality_deviation_id')
                ->constrained('quality_deviations')
                ->cascadeOnDelete();
            $table->foreignId('work_order_quality_control_id')->nullable()
                ->constrained('work_order_quality_controls')
                ->cascadeOnDelete();
            $table->foreignId('work_order_quality_control_point_id')->nullable()
                ->constrained('work_order_quality_control_points', 'id', 'qdev_targets_point_foreign')
                ->cascadeOnDelete();
            $table->foreignId('non_conformity_id')->nullable()
                ->constrained('non_conformities')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->index('work_order_quality_control_id', 'quality_deviation_targets_control_index');
            $table->index('work_order_quality_control_point_id', 'quality_deviation_targets_point_index');
            $table->index('non_conformity_id', 'quality_deviation_targets_nc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_deviation_targets');
    }
};
