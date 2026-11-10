<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_status_histories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('non_conformity_id')
                ->constrained('non_conformities')
                ->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['non_conformity_id', 'id'], 'non_conformity_histories_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_status_histories');
    }
};
