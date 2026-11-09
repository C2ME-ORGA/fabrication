<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_operation_audit_logs', function (Blueprint $table): void {
            $table->id();

            // L'audit doit survivre à l'opération et à l'OF : les références
            // deviennent nulles, mais le numéro d'OF et le code d'opération
            // sont conservés dans les colonnes dénormalisées.
            $table->foreignId('work_order_operation_id')->nullable()
                ->constrained('work_order_operations', 'id', 'wo_operation_audit_operation_fk')
                ->nullOnDelete();

            $table->foreignId('work_order_id')->nullable()
                ->constrained('work_orders', 'id', 'wo_operation_audit_work_order_fk')
                ->nullOnDelete();

            $table->string('work_order_number', 20)->nullable()->index();
            $table->string('operation_code', 50)->nullable();

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

            $table->index(['work_order_operation_id', 'created_at'], 'work_order_operation_audit_operation_index');
            $table->index(['work_order_id', 'created_at'], 'work_order_operation_audit_work_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_operation_audit_logs');
    }
};
