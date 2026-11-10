<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_control_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_quality_control_point_id')
                ->constrained('work_order_quality_control_points', 'id', 'qc_results_point_foreign')
                ->cascadeOnDelete();

            // Dénormalisations destinées aux requêtes et à l'audit : elles
            // restent lisibles même si la cible opérationnelle évolue.
            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->cascadeOnDelete();
            $table->foreignId('work_order_item_id')
                ->nullable()
                ->constrained('work_order_items')
                ->nullOnDelete();

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');

            $table->boolean('value_boolean')->nullable();
            $table->decimal('value_numeric', 14, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->string('value_choice', 100)->nullable();

            $table->string('unit_snapshot', 20)->nullable();
            $table->decimal('tolerance_min_snapshot', 14, 4)->nullable();
            $table->decimal('tolerance_max_snapshot', 14, 4)->nullable();

            $table->boolean('is_conform')->nullable();
            $table->json('evaluation_detail')->nullable();
            $table->text('comment')->nullable();

            $table->foreignId('supersedes_result_id')
                ->nullable()
                ->constrained('quality_control_results')
                ->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['work_order_quality_control_point_id', 'id'], 'quality_results_point_index');
            $table->index(['work_order_id', 'id'], 'quality_results_work_order_index');
            $table->index('work_order_item_id', 'quality_results_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_control_results');
    }
};
