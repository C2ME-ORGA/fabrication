<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_verifications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('non_conformity_id')
                ->constrained('non_conformities')
                ->cascadeOnDelete();

            $table->string('type', 30);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->string('result', 20);
            $table->text('comment');
            $table->foreignId('counter_control_id')->nullable()
                ->constrained('work_order_quality_controls')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['non_conformity_id', 'type', 'id'], 'non_conformity_verifications_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_verifications');
    }
};
