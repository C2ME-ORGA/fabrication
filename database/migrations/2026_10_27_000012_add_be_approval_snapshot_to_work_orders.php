<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le snapshot d'approbation BE est stocké dans la même table que les
        // snapshots de libération, distingué par son reason. Une colonne sur
        // work_orders permet de rattacher directement l'approbation à son
        // snapshot documentaire.
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('be_approval_snapshot_id')->nullable()
                ->constrained('work_order_document_snapshots')->nullOnDelete()
                ->after('be_approved_at');

            // Batch UUID de l'approbation BE, stocké directement sur l'OF pour
            // permettre la comparaison même quand aucun document n'est applicable
            // (aucun snapshot n'est alors créé).
            $table->string('be_approval_batch_id', 64)->nullable()
                ->after('be_approval_snapshot_id');
        });

        // Un document applicable peut ne pas avoir de version approuvée au
        // moment de l'approbation BE. Le snapshot doit pouvoir représenter
        // cette absence sans inventer de version.
        Schema::table('work_order_document_snapshots', function (Blueprint $table): void {
            $table->foreignId('document_version_id')->nullable()->change();
            $table->string('revision', 32)->nullable()->change();
            $table->unsignedInteger('version_number')->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();
            // Identifie le groupe de snapshots appartenant à une même
            // approbation BE. Plusieurs lignes (une par document applicable)
            // partagent le même batch_id.
            $table->string('be_approval_batch_id', 64)->nullable()->index()
                ->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['be_approval_snapshot_id']);
            $table->dropColumn('be_approval_snapshot_id');
            $table->dropColumn('be_approval_batch_id');
        });

        Schema::table('work_order_document_snapshots', function (Blueprint $table): void {
            $table->dropIndex(['be_approval_batch_id']);
            $table->dropColumn('be_approval_batch_id');
            $table->string('revision', 32)->nullable(false)->change();
            $table->unsignedInteger('version_number')->nullable(false)->change();
            $table->char('sha256', 64)->nullable(false)->change();
            $table->foreignId('document_version_id')->nullable(false)->change();
        });
    }
};
