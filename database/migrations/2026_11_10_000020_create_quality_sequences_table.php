<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->smallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'year'], 'quality_sequences_type_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_sequences');
    }
};
