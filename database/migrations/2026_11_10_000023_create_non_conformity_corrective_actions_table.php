<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_corrective_actions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('non_conformity_id')
                ->constrained('non_conformities')
                ->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->text('description');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('evidence_comment')->nullable();
            $table->boolean('verification_required')->default(true);
            $table->timestamps();

            $table->unique(
                ['non_conformity_id', 'position'],
                'non_conformity_actions_position_unique',
            );
            $table->index(['non_conformity_id', 'status'], 'non_conformity_actions_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_corrective_actions');
    }
};
