<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_operation_dependencies', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_operation_id')
                ->constrained('work_order_operations', 'id', 'wo_operation_dep_operation_fk')
                ->cascadeOnDelete();

            $table->foreignId('depends_on_operation_id')
                ->constrained('work_order_operations', 'id', 'wo_operation_dep_target_fk')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['work_order_operation_id', 'depends_on_operation_id'],
                'work_order_operation_dependencies_unique',
            );
            $table->index('depends_on_operation_id', 'work_order_operation_dependencies_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_operation_dependencies');
    }
};
