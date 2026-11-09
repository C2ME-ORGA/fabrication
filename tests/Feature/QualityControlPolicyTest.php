<?php

namespace Tests\Feature;

use App\Enums\QualityControlResultType;
use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityControlType;
use App\Models\QualityAuditLog;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Models\WorkshopRouting;
use App\Services\QualityControlPointService;
use App\Services\QualityControlTemplateService;
use App\Services\QualityControlTemplateVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class QualityControlPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_quality_managers_and_administrators_can_reach_the_referential(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $quality = User::factory()->qualityManager()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$operator, $supervisor] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', QualityControlTemplate::class));
            $this->assertFalse(Gate::forUser($user)->allows('create', QualityControlTemplate::class));
        }

        foreach ([$quality, $admin] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', QualityControlTemplate::class));
            $this->assertTrue(Gate::forUser($user)->allows('create', QualityControlTemplate::class));
        }
    }

    public function test_points_are_editable_only_on_a_draft_version(): void
    {
        $quality = User::factory()->qualityManager()->create();
        $actor = $quality;

        $template = app(QualityControlTemplateService::class)->create([
            'code' => 'POL-01',
            'name' => 'Modèle',
            'control_type' => QualityControlType::Dimensional->value,
        ], $actor);

        $version = $template->draftVersion();
        $point = app(QualityControlPointService::class)->create($version, [
            'label' => 'Point',
            'result_type' => QualityControlResultType::Conformity->value,
            'sampling_mode' => QualityControlSamplingMode::All->value,
            'is_mandatory' => true,
        ]);

        $this->assertTrue(Gate::forUser($quality)->allows('update', $point));

        app(QualityControlTemplateVersionService::class)->activate($version->fresh(), $actor, 'Version initiale.');

        $this->assertFalse(Gate::forUser($quality)->allows('update', $point->fresh()));
        $this->assertFalse(Gate::forUser($quality)->allows('delete', $point->fresh()));
    }

    public function test_quality_requirements_are_managed_by_supervisors_quality_managers_and_admins(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $quality = User::factory()->qualityManager()->create();
        $admin = User::factory()->admin()->create();

        $routing = WorkshopRouting::factory()->create();

        $this->assertFalse(Gate::forUser($operator)->allows('manageQualityRequirements', $routing));

        foreach ([$supervisor, $quality, $admin] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('manageQualityRequirements', $routing));
        }
    }

    public function test_the_quality_manager_flag_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->update(['is_quality_manager' => true]);

        $this->assertFalse($user->fresh()->isQualityManager());
    }

    public function test_the_audit_log_is_append_only_through_eloquent(): void
    {
        $log = QualityAuditLog::factory()->create();

        try {
            $log->update(['comment' => 'modifié']);
            $this->fail('La modification aurait dû être refusée.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        try {
            $log->delete();
            $this->fail('La suppression aurait dû être refusée.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('quality_audit_logs', ['id' => $log->getKey()]);
    }
}
