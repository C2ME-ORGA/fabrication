<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_time_entries', function (Blueprint $table): void {
            $table->id();

            // Une opération portant des temps ne doit pas être supprimée :
            // l'historique de production doit rester consultable.
            $table->foreignId('work_order_operation_id')
                ->constrained('work_order_operations')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('entry_type', 20)->default('timer');

            $table->foreignId('created_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('corrected_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Garantie portable SQLite/MySQL : un opérateur ne peut avoir
            // qu'une seule session active (ended_at NULL), tous OF confondus.
            // La colonne générée vaut user_id pendant la session et NULL après
            // clôture ; l'index unique ignore alors les lignes historiques.
            $table->unsignedBigInteger('active_user_id')->nullable()
                ->storedAs('CASE WHEN ended_at IS NULL THEN user_id ELSE NULL END');

            $table->unique('active_user_id', 'operation_time_entries_active_user_unique');
            $table->index(['work_order_operation_id', 'started_at']);
            $table->index(['user_id', 'started_at']);
            $table->index('entry_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_time_entries');
    }
};
