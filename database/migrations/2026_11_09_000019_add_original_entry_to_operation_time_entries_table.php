<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_time_entries', function (Blueprint $table): void {
            // Relation append-only entre une correction et l'entrée d'origine.
            // L'original n'est jamais modifié ni supprimé.
            $table->foreignId('original_entry_id')->nullable()
                ->after('work_order_operation_id')
                ->constrained('operation_time_entries')
                ->nullOnDelete();

            $table->index('original_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('operation_time_entries', function (Blueprint $table): void {
            $table->dropForeign(['original_entry_id']);
            $table->dropIndex(['original_entry_id']);
            $table->dropColumn('original_entry_id');
        });
    }
};
