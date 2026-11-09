<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Rôle atelier explicite, distinct de l'accès administrateur.
            // Ce champ n'est jamais mass assignable : il est modifié
            // uniquement par une action d'administration dédiée.
            $table->boolean('is_workshop_supervisor')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_workshop_supervisor');
        });
    }
};
