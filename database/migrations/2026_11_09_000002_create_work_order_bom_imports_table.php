<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_bom_imports', function (Blueprint $table): void {
            $table->id();

            // L'historique des imports ne doit jamais disparaître silencieusement
            // avec la suppression d'un OF. La suppression est donc restreinte.
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();

            $table->string('original_filename');
            $table->string('storage_disk', 32);
            $table->string('storage_path')->unique();
            $table->char('sha256', 64);
            $table->json('column_mapping');

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);

            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at');
            $table->string('status', 20)->default('completed');

            $table->timestamps();

            // Un même fichier ne peut être importé qu'une fois sur un OF donné.
            $table->unique(['work_order_id', 'sha256']);
            $table->index(['work_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_bom_imports');
    }
};
