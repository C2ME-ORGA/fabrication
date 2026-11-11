<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_quality_releases', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->foreignId('released_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('released_at');
            $table->string('phase', 20)->default('closure');
            $table->boolean('eligible');
            $table->boolean('requirements_present')->default(false);
            $table->json('eligibility_snapshot');
            $table->char('snapshot_hash', 64);
            $table->string('rules_version', 50)->nullable();
            $table->string('reason', 20);
            $table->text('comment')->nullable();

            // Preuve append-only : aucune colonne updated_at n'est maintenue.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['work_order_id', 'released_at']);
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_quality_releases');
    }
};
