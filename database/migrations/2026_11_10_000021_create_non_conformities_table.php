<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformities', function (Blueprint $table): void {
            $table->id();

            $table->string('reference', 30)->unique();
            $table->string('origin', 30)->default('work_order');

            // Le numéro est conservé même si l'OF est supprimé.
            $table->foreignId('work_order_id')->nullable()
                ->constrained('work_orders')
                ->nullOnDelete();
            $table->string('work_order_number', 20)->nullable();

            $table->foreignId('work_order_item_id')->nullable()
                ->constrained('work_order_items')
                ->nullOnDelete();
            $table->foreignId('work_order_operation_id')->nullable()
                ->constrained('work_order_operations')
                ->nullOnDelete();
            $table->foreignId('work_order_quality_control_id')->nullable()
                ->constrained('work_order_quality_controls')
                ->nullOnDelete();
            $table->foreignId('quality_control_result_id')->nullable()
                ->constrained('quality_control_results')
                ->nullOnDelete();

            $table->text('description');
            $table->text('criterion')->nullable();
            $table->string('severity', 20);
            $table->string('initial_severity', 20);
            $table->string('category', 30);
            $table->string('status', 20)->default('open');

            // Politique de gravité figée à la création : elle ne change
            // jamais, même après une requalification explicite.
            $table->boolean('is_blocking')->default(false);
            $table->boolean('verification_required')->default(false);
            $table->boolean('counter_control_required')->default(false);
            $table->json('severity_policy_snapshot');

            // Politique effectivement appliquée à la gravité courante. Elle
            // est initialisée à la création puis mise à jour uniquement lors
            // d'une requalification explicite et auditée.
            $table->json('current_severity_policy_snapshot');

            $table->timestamp('detected_at');
            $table->foreignId('detected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();

            $table->text('treatment_plan')->nullable();
            $table->text('immediate_action')->nullable();
            $table->timestamp('containment_at')->nullable();

            $table->text('root_cause')->nullable();
            $table->string('root_cause_method', 30)->nullable();

            $table->foreignId('qualified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('qualified_at')->nullable();
            $table->text('qualification_comment')->nullable();

            $table->timestamp('taken_in_charge_at')->nullable();
            $table->timestamp('treated_at')->nullable();
            $table->timestamp('verification_due_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_comment')->nullable();

            $table->timestamps();

            // Anti-doublon portable : une seule NC non annulée par résultat
            // source. Une NC annulée libère le résultat ; une NC clôturée
            // conserve au contraire l'unicité. La valeur est maintenue par
            // les services métier (MySQL refuse une colonne générée qui
            // référence une colonne portant une clé étrangère).
            $table->unsignedBigInteger('unique_result_id')->nullable();

            $table->unique('unique_result_id', 'non_conformities_result_unique');
            $table->index(['status', 'severity'], 'non_conformities_status_severity_index');
            $table->index(['work_order_id', 'status'], 'non_conformities_work_order_status_index');
            $table->index(['responsible_id', 'due_date'], 'non_conformities_responsible_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformities');
    }
};
