<?php

namespace Tests\Feature;

use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentWorkOrderFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_work_order_from_the_filament_form(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();

        $this->actingAs($admin);

        Livewire::test(CreateWorkOrder::class)
            ->fillForm([
                // A crafted payload cannot impose the number.
                'number' => 'OF-2099-9999',
                'job_id' => $job->id,
                'title' => 'Créé depuis Filament',
                'priority' => WorkOrderPriority::High->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $workOrder = WorkOrder::query()->where('title', 'Créé depuis Filament')->firstOrFail();

        $this->assertMatchesRegularExpression('/^OF-\d{4}-0001$/', $workOrder->number);
        $this->assertNotSame('OF-2099-9999', $workOrder->number);
        $this->assertSame(WorkOrderStatus::Draft, $workOrder->status);
        $this->assertSame(WorkOrderPriority::High, $workOrder->priority);
        $this->assertSame($job->id, $workOrder->job_id);
        $this->assertSame($admin->id, $workOrder->created_by);
    }

    public function test_an_admin_can_edit_a_draft_work_order_from_the_filament_form(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($admin);

        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->assertFormSet(['job_id' => $workOrder->job_id])
            ->fillForm([
                'title' => 'Titre modifié',
                'priority' => WorkOrderPriority::Urgent->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $workOrder->refresh();

        $this->assertSame('Titre modifié', $workOrder->title);
        $this->assertSame(WorkOrderPriority::Urgent, $workOrder->priority);
    }

    public function test_the_form_prefills_the_job_from_the_relation_manager_link(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Job::factory()->active()->create();

        $this->actingAs($admin);

        Livewire::withQueryParams(['job_id' => $job->id])
            ->test(CreateWorkOrder::class)
            ->assertFormSet(['job_id' => $job->id]);
    }
}
