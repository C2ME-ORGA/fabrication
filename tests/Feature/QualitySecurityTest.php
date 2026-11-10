<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\NonConformity;
use App\Models\QualityControlResult;
use App\Models\QualityControlResultAttachment;
use App\Models\QualityDeviation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityControl;
use App\Services\NonConformityService;
use App\Services\QualityControlResultService;
use App\Services\QualityDeviationService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

/**
 * Revue de sécurité du Lot 7 : IDOR sur les preuves, mass assignment et XSS.
 */
class QualitySecurityTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    public function test_evidence_download_is_refused_when_the_user_cannot_view_the_parent_control(): void
    {
        Storage::fake('documents');

        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();

        [$workOrder, $attachment] = $this->resultAttachment($admin, $operator);

        $workOrder->forceFill([
            'status' => WorkOrderStatus::Completed,
            'actual_end_at' => now(),
        ])->save();

        $this->actingAs($operator)
            ->get(route('filament.workshop.quality.result-attachments.download', $attachment))
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->get(route('filament.workshop.quality.result-attachments.download', $attachment))
            ->assertOk()
            ->assertDownload($attachment->original_filename);
    }

    public function test_non_conformity_and_deviation_evidence_inherit_parent_policies(): void
    {
        Storage::fake('documents');

        $qualityManager = User::factory()->qualityManager()->create();
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create([
            'released_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'created_by' => $qualityManager->getKey(),
            'detected_by' => $qualityManager->getKey(),
        ]);

        $nonConformityAttachment = app(NonConformityService::class)->attachEvidence(
            $nonConformity,
            UploadedFile::fake()->image('nc.png', 60, 60),
            $qualityManager,
        );

        $deviation = QualityDeviation::factory()->active()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'requested_by' => $qualityManager->getKey(),
        ]);

        $deviationAttachment = app(QualityDeviationService::class)->attachEvidence(
            $deviation,
            UploadedFile::fake()->image('derogation.png', 60, 60),
            $qualityManager,
        );

        $this->actingAs($operator)
            ->get(route('filament.workshop.quality.non-conformity-attachments.download', $nonConformityAttachment))
            ->assertOk();

        $this->actingAs($operator)
            ->get(route('filament.workshop.quality.deviation-attachments.download', $deviationAttachment))
            ->assertForbidden();

        $this->actingAs($qualityManager)
            ->get(route('filament.workshop.quality.deviation-attachments.download', $deviationAttachment))
            ->assertOk();
    }

    public function test_quality_models_refuse_mass_assignment_of_sensitive_fields(): void
    {
        $this->assertMassAssignmentRefused(
            new WorkOrderQualityControl,
            ['template_code' => 'INJECTED', 'status' => 'passed', 'is_mandatory' => false],
        );

        $this->assertMassAssignmentRefused(
            new QualityControlResult,
            ['is_conform' => false, 'value_boolean' => false],
        );
    }

    public function test_comments_are_escaped_in_the_non_conformity_screen(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'work_order_number' => $workOrder->number,
            'description' => '<script>alert("xss")</script> Description contrôlée.',
        ]);

        $this->actingAs($admin)
            ->get("/admin/non-conformities/{$nonConformity->getKey()}")
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;alert', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function assertMassAssignmentRefused(object $model, array $attributes): void
    {
        try {
            $model->fill($attributes);
            $this->fail('Une affectation de masse sensible aurait dû être refusée.');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array{0: WorkOrder, 1: QualityControlResultAttachment}
     */
    protected function resultAttachment(User $admin, User $operator): array
    {
        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle avec preuve']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $point = $control->points()->sole();

        $result = app(QualityControlResultService::class)->record(
            $point,
            [
                'value_boolean' => true,
                'evidence' => [UploadedFile::fake()->image('preuve.png', 80, 80)],
            ],
            $operator,
        );

        return [$workOrder->fresh(), $result->attachments()->sole()];
    }
}
