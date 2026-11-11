<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table): void {
            $table->id();

            // Un PDF d'OF appartient toujours à un OF. La référence est
            // conservée même si l'OF ne peut plus être supprimé.
            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            // Le Lot 2 ne crée pas encore la table shipments. La colonne est
            // donc ajoutée sans FK ; la contrainte sera ajoutée au Lot 3.
            $table->unsignedBigInteger('shipment_id')->nullable()->index();

            $table->string('type', 30)->index();
            $table->string('scope_key', 100);
            $table->unsignedInteger('version');
            $table->string('reference', 50)->unique();
            $table->string('status', 20)->default('archived')->index();

            $table->string('disk', 32);
            $table->string('path', 500)->unique();
            $table->string('original_filename');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();

            $table->string('template_version', 30);
            $table->char('source_data_hash', 64);
            $table->string('of_revision', 100)->nullable();
            $table->json('revision_snapshot')->nullable();
            $table->json('visa_snapshot')->nullable();

            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('generated_at');

            $table->foreignId('supersedes_id')
                ->nullable()
                ->constrained('generated_documents')
                ->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['scope_key', 'version']);
            $table->index(['work_order_id', 'type', 'generated_at']);
            $table->index(['shipment_id', 'type', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
