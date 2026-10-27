<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->string('category', 40)->index();
            $table->string('title');
            $table->string('reference')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // FK added after document_versions exists (circular reference).
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->timestamps();

            $table->index(['job_id', 'status']);
            $table->index(['work_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
