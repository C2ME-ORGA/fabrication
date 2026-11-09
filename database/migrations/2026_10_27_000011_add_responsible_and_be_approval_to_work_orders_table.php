<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            // Responsable opérationnel de l'OF, distinct du créateur et du libérateur.
            $table->foreignId('responsible_id')->nullable()
                ->constrained('users')->nullOnDelete()
                ->after('job_id');

            // Approbation explicite du bureau d'études avant libération.
            $table->foreignId('be_approved_by')->nullable()
                ->constrained('users')->nullOnDelete()
                ->after('released_by');
            $table->timestamp('be_approved_at')->nullable()
                ->after('be_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['responsible_id']);
            $table->dropColumn('responsible_id');

            $table->dropForeign(['be_approved_by']);
            $table->dropColumn('be_approved_by');
            $table->dropColumn('be_approved_at');
        });
    }
};
