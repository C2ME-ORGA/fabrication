<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_control_result_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('quality_control_result_id')
                ->constrained('quality_control_results', 'id', 'qc_attachments_result_foreign')
                ->cascadeOnDelete();
            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->cascadeOnDelete();

            $table->string('disk', 50);
            $table->string('path', 500)->unique();
            $table->string('original_filename', 255);
            $table->string('extension', 10);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_control_result_attachments');
    }
};
