<?php

namespace Tests\Feature;

use App\Enums\QualityControlTemplateVersionStatus;
use App\Models\QualityControlPoint;
use App\Models\QualityControlTemplate;
use App\Models\QualityControlTemplateVersion;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Models\WorkshopRoutingOperation;
use App\Models\WorkshopRoutingOperationQualityRequirement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QualitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_quality_tables_are_present(): void
    {
        foreach ([
            'quality_control_templates',
            'quality_control_template_versions',
            'quality_control_points',
            'workshop_routing_operation_quality_requirements',
            'quality_audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La table {$table} est absente.");
        }
    }

    public function test_template_tables_expose_the_expected_columns(): void
    {
        foreach ([
            'code', 'name', 'description', 'control_type', 'is_active', 'current_version_id', 'created_by',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_control_templates', $column), "Colonne manquante : {$column}");
        }

        foreach ([
            'quality_control_template_id', 'version_number', 'status', 'change_note',
            'activated_by', 'activated_at', 'retired_at', 'created_by', 'active_template_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_control_template_versions', $column), "Colonne manquante : {$column}");
        }

        foreach ([
            'quality_control_template_version_id', 'position', 'label', 'instructions',
            'result_type', 'unit', 'tolerance_min', 'tolerance_max',
            'is_mandatory', 'is_critical', 'allow_deviation',
            'sampling_mode', 'sampling_every', 'required_results', 'options',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_control_points', $column), "Colonne manquante : {$column}");
        }

        foreach ([
            'workshop_routing_operation_id', 'quality_control_template_id', 'scope', 'trigger',
            'is_mandatory', 'position',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('workshop_routing_operation_quality_requirements', $column),
                "Colonne manquante : {$column}",
            );
        }

        foreach ([
            'work_order_id', 'work_order_number', 'subject_type', 'subject_id', 'subject_reference',
            'user_id', 'event', 'from_status', 'to_status', 'comment', 'metadata', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('quality_audit_logs', $column), "Colonne manquante : {$column}");
        }
    }

    public function test_users_expose_the_quality_manager_flag_disabled_by_default(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_quality_manager'));

        $user = User::factory()->create();

        $this->assertFalse($user->fresh()->isQualityManager());
    }

    public function test_point_defaults_are_conservative(): void
    {
        $template = QualityControlTemplate::factory()->create();
        $version = QualityControlTemplateVersion::factory()->create([
            'quality_control_template_id' => $template->getKey(),
        ]);

        $point = new QualityControlPoint;
        $point->fill([
            'label' => 'Point par défaut',
            'result_type' => 'conformity',
        ]);
        $point->quality_control_template_version_id = $version->getKey();
        $point->position = 10;
        $point->save();
        $point->refresh();

        $this->assertTrue($point->is_mandatory);
        $this->assertFalse($point->is_critical);
        $this->assertFalse($point->allow_deviation);
        $this->assertSame('all', $point->getRawOriginal('sampling_mode'));
        $this->assertSame(1, $point->required_results);
    }

    public function test_only_one_active_version_is_allowed_per_template(): void
    {
        $template = QualityControlTemplate::factory()->create();

        QualityControlTemplateVersion::factory()->create([
            'quality_control_template_id' => $template->getKey(),
            'version_number' => 1,
            'status' => QualityControlTemplateVersionStatus::Active,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        QualityControlTemplateVersion::factory()->create([
            'quality_control_template_id' => $template->getKey(),
            'version_number' => 2,
            'status' => QualityControlTemplateVersionStatus::Active,
        ]);
    }

    public function test_draft_and_retired_versions_do_not_conflict_on_the_active_index(): void
    {
        $template = QualityControlTemplate::factory()->create();

        QualityControlTemplateVersion::factory()->create([
            'quality_control_template_id' => $template->getKey(),
            'version_number' => 1,
            'status' => QualityControlTemplateVersionStatus::Retired,
        ]);

        QualityControlTemplateVersion::factory()->create([
            'quality_control_template_id' => $template->getKey(),
            'version_number' => 2,
            'status' => QualityControlTemplateVersionStatus::Draft,
        ]);

        QualityControlTemplateVersion::factory()->create([
            'quality_control_template_id' => $template->getKey(),
            'version_number' => 3,
            'status' => QualityControlTemplateVersionStatus::Active,
        ]);

        $this->assertSame(3, $template->versions()->count());
    }

    public function test_requirement_uniqueness_is_enforced_per_operation_and_template(): void
    {
        $routing = WorkshopRouting::factory()->create();
        $operation = WorkshopRoutingOperation::factory()->forRouting($routing)->create();
        $template = QualityControlTemplate::factory()->create();

        $attributes = [
            'workshop_routing_operation_id' => $operation->getKey(),
            'quality_control_template_id' => $template->getKey(),
            'scope' => 'operation',
            'trigger' => 'on_completion',
            'is_mandatory' => true,
            'position' => 10,
        ];

        (new WorkshopRoutingOperationQualityRequirement)->forceFill($attributes)->save();

        $this->expectException(UniqueConstraintViolationException::class);

        (new WorkshopRoutingOperationQualityRequirement)->forceFill($attributes)->save();
    }
}
