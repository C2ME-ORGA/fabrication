<?php

namespace Tests\Feature;

use App\Models\NonConformity;
use App\Models\QualityControlResult;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NonConformitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_non_conformity_tables_and_columns_are_present(): void
    {
        foreach ([
            'quality_sequences',
            'non_conformities',
            'non_conformity_status_histories',
            'non_conformity_corrective_actions',
            'non_conformity_verifications',
            'non_conformity_attachments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }

        foreach ([
            'reference', 'origin', 'work_order_id', 'work_order_number',
            'work_order_item_id', 'work_order_operation_id',
            'work_order_quality_control_id', 'quality_control_result_id',
            'description', 'criterion', 'severity', 'initial_severity', 'category', 'status',
            'is_blocking', 'verification_required', 'counter_control_required',
            'severity_policy_snapshot', 'current_severity_policy_snapshot',
            'detected_at', 'detected_by', 'created_by',
            'responsible_id', 'due_date', 'treatment_plan', 'immediate_action',
            'containment_at', 'root_cause', 'root_cause_method',
            'qualified_by', 'qualified_at', 'qualification_comment',
            'taken_in_charge_at', 'treated_at', 'verification_due_at',
            'closed_at', 'closed_by', 'closure_comment', 'unique_result_id',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('non_conformities', $column),
                "Colonne manquante : {$column}",
            );
        }

        foreach ([
            'non_conformity_id', 'from_status', 'to_status', 'actor_id',
            'comment', 'metadata', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('non_conformity_status_histories', $column));
        }

        foreach ([
            'non_conformity_id', 'position', 'description', 'responsible_id',
            'due_date', 'status', 'completed_at', 'completed_by',
            'evidence_comment', 'verification_required',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('non_conformity_corrective_actions', $column));
        }

        foreach ([
            'non_conformity_id', 'type', 'performed_by', 'performed_at',
            'result', 'comment', 'counter_control_id', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('non_conformity_verifications', $column));
        }

        foreach ([
            'non_conformity_id', 'work_order_id', 'disk', 'path',
            'original_filename', 'extension', 'mime_type', 'size_bytes',
            'sha256', 'uploaded_by', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('non_conformity_attachments', $column));
        }
    }

    public function test_a_result_cannot_produce_two_non_conformities(): void
    {
        $result = QualityControlResult::factory()->create();
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'quality_control_result_id' => $result->getKey(),
            'detected_by' => $user->getKey(),
            'created_by' => $user->getKey(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $duplicate = new NonConformity;
        $duplicate->forceFill([
            'reference' => 'NC-2026-9999',
            'origin' => 'control_result',
            'work_order_id' => $workOrder->getKey(),
            'quality_control_result_id' => $result->getKey(),
            'unique_result_id' => $result->getKey(),
            'description' => 'Doublon interdit.',
            'severity' => 'minor',
            'initial_severity' => 'minor',
            'category' => 'other',
            'status' => 'open',
            'severity_policy_snapshot' => [],
            'current_severity_policy_snapshot' => [],
            'detected_at' => now(),
        ])->save();
    }

    public function test_a_cancelled_non_conformity_releases_the_result_but_a_closed_one_does_not(): void
    {
        $result = QualityControlResult::factory()->create();
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $cancelled = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'quality_control_result_id' => $result->getKey(),
            'status' => 'cancelled',
            'detected_by' => $user->getKey(),
            'created_by' => $user->getKey(),
        ]);

        $active = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'quality_control_result_id' => $result->getKey(),
            'status' => 'open',
            'detected_by' => $user->getKey(),
            'created_by' => $user->getKey(),
        ]);

        $this->assertDatabaseHas('non_conformities', ['id' => $cancelled->getKey(), 'status' => 'cancelled']);
        $this->assertDatabaseHas('non_conformities', ['id' => $active->getKey(), 'status' => 'open']);

        $active->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->expectException(UniqueConstraintViolationException::class);

        NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'quality_control_result_id' => $result->getKey(),
            'status' => 'open',
            'detected_by' => $user->getKey(),
            'created_by' => $user->getKey(),
        ]);
    }

    public function test_quality_sequences_are_unique_per_type_and_year(): void
    {
        DB::table('quality_sequences')->insert([
            'type' => 'non_conformity',
            'year' => 2026,
            'last_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('quality_sequences')->insert([
            'type' => 'non_conformity',
            'year' => 2026,
            'last_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
