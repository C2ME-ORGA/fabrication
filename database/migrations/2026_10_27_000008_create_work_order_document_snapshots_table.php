<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_document_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();

            // Snapshot of the version metadata at capture time.
            $table->string('category', 40);
            $table->string('title');
            $table->string('reference')->nullable();
            $table->string('revision', 32);
            $table->unsignedInteger('version_number');
            $table->char('sha256', 64);

            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();

            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at');
            $table->string('reason', 20)->index();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'document_id']);
            $table->index(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_document_snapshots');
    }
};
