<?php

namespace Tests\Feature;

use App\Models\QualityDeviation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class QualityDeviationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_deviation_tables_and_columns_are_present(): void
    {
        foreach ([
            'quality_deviations',
            'quality_deviation_targets',
            'quality_deviation_attachments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }

        foreach ([
            'reference', 'work_order_id', 'work_order_number', 'requested_by',
            'requested_at', 'granted_by', 'granted_at', 'second_approver_id',
            'second_approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
            'reason', 'description', 'scope', 'status', 'is_critical',
            'expires_at', 'revoked_by', 'revoked_at', 'revoke_reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_deviations', $column), "Colonne manquante : {$column}");
        }

        foreach ([
            'quality_deviation_id', 'work_order_quality_control_id',
            'work_order_quality_control_point_id', 'non_conformity_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_deviation_targets', $column));
        }

        foreach ([
            'quality_deviation_id', 'work_order_id', 'disk', 'path',
            'original_filename', 'extension', 'mime_type', 'size_bytes',
            'sha256', 'uploaded_by', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_deviation_attachments', $column));
        }
    }

    public function test_a_deviation_cannot_be_deleted(): void
    {
        $deviation = QualityDeviation::factory()->create();

        $this->expectException(RuntimeException::class);

        $deviation->delete();
    }
}
