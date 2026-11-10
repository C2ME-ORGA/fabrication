<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_deviations', function (Blueprint $table): void {
            $table->id();

            $table->string('reference', 30)->unique();

            $table->foreignId('work_order_id')->nullable()
                ->constrained('work_orders')
                ->nullOnDelete();
            $table->string('work_order_number', 20)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->foreignId('second_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->text('reason');
            $table->text('description')->nullable();
            $table->string('scope', 20);
            $table->string('status', 20)->default('pending');
            $table->boolean('is_critical')->default(false);
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['work_order_id', 'status'], 'quality_deviations_work_order_status_index');
            $table->index(['status', 'expires_at'], 'quality_deviations_status_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_deviations');
    }
};
