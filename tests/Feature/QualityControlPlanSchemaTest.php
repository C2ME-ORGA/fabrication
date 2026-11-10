<?php

namespace Tests\Feature;

use App\Models\QualityControlResult;
use App\Models\QualityControlResultValidation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QualityControlPlanSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_quality_tables_and_columns_are_present(): void
    {
        foreach ([
            'work_order_quality_controls',
            'work_order_quality_control_points',
            'quality_control_results',
            'quality_control_result_validations',
            'quality_control_result_attachments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }

        foreach ([
            'work_order_id', 'work_order_operation_id', 'work_order_item_id',
            'source_requirement_id', 'source_type', 'template_code', 'template_name',
            'template_version_number', 'control_type', 'scope', 'trigger',
            'is_mandatory', 'allow_deviation', 'position', 'status',
            'required_by_at', 'completed_at', 'completed_by', 'snapshot_metadata',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_order_quality_controls', $column),
                "Colonne manquante : {$column}",
            );
        }

        foreach ([
            'work_order_quality_control_id', 'position', 'label', 'instructions',
            'result_type', 'unit', 'tolerance_min', 'tolerance_max',
            'is_mandatory', 'is_critical', 'allow_deviation',
            'sampling_mode', 'sampling_every', 'required_results',
            'sampled_item_ids', 'options',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('work_order_quality_control_points', $column),
                "Colonne manquante : {$column}",
            );
        }

        foreach ([
            'work_order_quality_control_point_id', 'work_order_id', 'work_order_item_id',
            'performed_by', 'performed_at', 'value_boolean', 'value_numeric',
            'value_text', 'value_choice', 'unit_snapshot', 'tolerance_min_snapshot',
            'tolerance_max_snapshot', 'is_conform', 'evaluation_detail', 'comment',
            'supersedes_result_id', 'recorded_by', 'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('quality_control_results', $column),
                "Colonne manquante : {$column}",
            );
        }

        foreach ([
            'quality_control_result_id', 'decision', 'validated_by',
            'validated_at', 'comment', 'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('quality_control_result_validations', $column),
                "Colonne manquante : {$column}",
            );
        }

        foreach ([
            'quality_control_result_id', 'work_order_id', 'disk', 'path',
            'original_filename', 'extension', 'mime_type', 'size_bytes',
            'sha256', 'uploaded_by', 'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('quality_control_result_attachments', $column),
                "Colonne manquante : {$column}",
            );
        }
    }

    public function test_a_source_requirement_cannot_generate_two_controls_for_the_same_work_order(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $requirement = WorkshopRoutingOperationQualityRequirement::factory()->create();

        WorkOrderQualityControl::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'source_requirement_id' => $requirement->getKey(),
            'source_type' => 'routing',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $duplicate = new WorkOrderQualityControl;
        $duplicate->forceFill([
            'work_order_id' => $workOrder->getKey(),
            'source_requirement_id' => $requirement->getKey(),
            'source_type' => 'routing',
            'template_code' => 'DUP',
            'template_name' => 'Doublon',
            'template_version_number' => 1,
            'control_type' => 'visual',
            'scope' => 'operation',
            'trigger' => 'on_completion',
            'position' => 10,
            'status' => 'pending',
        ])->save();
    }

    public function test_the_same_user_cannot_validate_the_same_result_twice(): void
    {
        $user = User::factory()->create();
        $result = QualityControlResult::factory()->create();

        QualityControlResultValidation::factory()->create([
            'quality_control_result_id' => $result->getKey(),
            'validated_by' => $user->getKey(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        QualityControlResultValidation::factory()->create([
            'quality_control_result_id' => $result->getKey(),
            'validated_by' => $user->getKey(),
        ]);
    }
}
