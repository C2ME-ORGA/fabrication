<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_document_reviews', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->foreignId('shipment_id')
                ->nullable()
                ->constrained('shipments')
                ->nullOnDelete();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->restrictOnDelete();

            $table->foreignId('document_version_id')
                ->constrained('document_versions')
                ->restrictOnDelete();

            $table->string('decision', 20);
            $table->text('comment');
            $table->json('revision_snapshot');

            $table->foreignId('decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['work_order_id', 'document_id', 'document_version_id', 'decided_at'],
                'shipment_review_target_index',
            );
            $table->index(['shipment_id', 'decided_at'], 'shipment_review_shipment_index');
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_document_reviews');
    }
};
