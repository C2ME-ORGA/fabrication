<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->foreign('shipment_id')
                ->references('id')
                ->on('shipments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropForeign(['shipment_id']);
        });
    }
};
