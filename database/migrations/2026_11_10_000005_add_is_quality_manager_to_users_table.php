<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Responsable qualité : gère le référentiel, valide les contrôles
            // et accorde les dérogations. Le drapeau n'est pas mass assignable.
            $table->boolean('is_quality_manager')
                ->default(false)
                ->after('is_workshop_supervisor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_quality_manager');
        });
    }
};
