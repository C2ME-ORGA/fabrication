<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_audit_logs', function (Blueprint $table): void {
            $table->id();

            // Le journal doit survivre à l'OF : la référence devient nulle,
            // mais le numéro dénormalisé est conservé.
            $table->foreignId('work_order_id')->nullable()
                ->constrained('work_orders')
                ->nullOnDelete();
            $table->string('work_order_number', 20)->nullable()->index();

            // Référence typée explicite (pas de morphTo) : le domaine qualité
            // couvre plusieurs entités et la trace doit survivre à leur
            // suppression. subject_reference est une copie lisible.
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_reference', 80)->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('event', 50)->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();

            // Journal immuable : seule la date de création est conservée.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['work_order_id', 'created_at'], 'quality_audit_work_order_index');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'quality_audit_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_audit_logs');
    }
};
