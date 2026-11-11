<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderQualityRelease;
use App\Services\WorkOrderPrintService;
use App\Services\WorkOrderQualityReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderQualityReleaseFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_a_historical_quality_release_from_the_work_order_page(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();

        $this->actingAs($admin);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->assertActionExists('recordHistoricalQualityRelease')
            ->callAction('recordHistoricalQualityRelease', data: [
                'comment' => 'Revue historique de la qualité.',
            ])
            ->assertHasNoActionErrors();

        $release = WorkOrderQualityRelease::query()->sole();

        $this->assertSame($workOrder->getKey(), $release->work_order_id);
        $this->assertSame($admin->getKey(), $release->released_by);

        $data = app(WorkOrderPrintService::class)->build($workOrder->fresh(), $admin);

        $this->assertSame('effectue', $data->visaSnapshot['qualite']['status']);
        $this->assertSame($release->getKey(), $data->visaSnapshot['qualite']['evidence_id']);
    }

    public function test_action_is_hidden_when_a_release_already_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();

        app(WorkOrderQualityReleaseService::class)
            ->recordHistoricalReview($workOrder, $admin, 'Première revue.');

        $this->actingAs($admin);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->assertActionHidden('recordHistoricalQualityRelease');
    }

    public function test_workshop_supervisor_cannot_record_a_quality_release(): void
    {
        $workshop = User::factory()->workshopSupervisor()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Completed)->create();

        $this->assertFalse($workshop->can('releaseQuality', $workOrder));
    }
}
