<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_control_result_validations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('quality_control_result_id')
                ->constrained('quality_control_results', 'id', 'qc_validations_result_foreign')
                ->cascadeOnDelete();

            $table->string('decision', 20);
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['quality_control_result_id', 'id'], 'quality_validations_result_index');
            $table->unique(
                ['quality_control_result_id', 'validated_by'],
                'quality_validations_user_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_control_result_validations');
    }
};
