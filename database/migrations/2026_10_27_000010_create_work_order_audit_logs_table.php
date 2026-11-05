<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_order_audit_logs', function (Blueprint $table): void {
            $table->id();

            // L'historique doit survivre à l'OF : la suppression d'un OF ne détruit pas
            // ses entrées d'audit (work_order_id passe à NULL).
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();

            // Référence immuable à l'OF d'origine, conservée même après suppression de l'OF.
            // Numéro adapté à la colonne work_orders.number. Resté nullable pour conserver
            // la compatibilité avec d'anciens événements orphelins dont l'OF a été supprimé
            // avant cette migration (aucun numéro n'est inventé pour eux).
            $table->string('work_order_number', 20)->nullable()->index();

            // L'utilisateur à l'origine de l'action, nullable pour les opérations système.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Type d'événement (création, transition d'état, annulation, etc.).
            $table->string('event', 50)->index();

            // État avant et après la transition (null pour la création initiale).
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            // Motif ou commentaire associé à l'action (ex: motif d'annulation).
            $table->text('comment')->nullable();

            // Données complémentaires facultatives au format JSON.
            $table->json('metadata')->nullable();

            // Journal immuable : seule la date de création est conservée.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['work_order_id', 'created_at']);
        });

        // Remplissage du numéro d'OF depuis la table work_orders pour les entrées existantes
        // liées à un OF encore présent. Les événements orphelins (work_order_id = NULL)
        // conservent une valeur NULL : aucun numéro n'est inventé pour eux.
        DB::statement(
            'UPDATE work_order_audit_logs SET work_order_number = (
                SELECT number FROM work_orders WHERE work_orders.id = work_order_audit_logs.work_order_id
            ) WHERE work_order_id IS NOT NULL AND work_order_number IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_audit_logs');
    }
};
