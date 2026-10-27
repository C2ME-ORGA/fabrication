<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();

            // Restrict deletion: an affaire holding work orders must not be
            // deleted, so history is never lost silently.
            $table->foreignId('job_id')->constrained('jobs')->restrictOnDelete();
            $table->string('number')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('priority', 20)->default('normal');
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['job_id', 'status']);
            $table->index('planned_end_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
