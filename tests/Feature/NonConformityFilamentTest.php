<?php

namespace Tests\Feature;

use App\Enums\NonConformityCategory;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\NonConformities\Pages\CreateNonConformity;
use App\Filament\Resources\NonConformities\Pages\ListNonConformities;
use App\Filament\Resources\NonConformities\Pages\ViewNonConformity;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\QualityControlsRelationManager;
use App\Models\NonConformity;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualityControlResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsQualityPlan;
use Tests\TestCase;

class NonConformityFilamentTest extends TestCase
{
    use BuildsQualityPlan;
    use RefreshDatabase;

    public function test_an_admin_can_list_and_view_non_conformities(): void
    {
        $admin = User::factory()->admin()->create();
        $nonConformity = NonConformity::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/non-conformities')
            ->assertOk()
            ->assertSee($nonConformity->reference);

        $this->actingAs($admin)
            ->get('/admin/non-conformities/'.$nonConformity->getKey())
            ->assertOk()
            ->assertSee($nonConformity->reference)
            ->assertSee($nonConformity->severity->getLabel());
    }

    public function test_an_admin_can_declare_a_manual_non_conformity_from_filament(): void
    {
        $admin = User::factory()->admin()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateNonConformity::class)
            ->fillForm([
                'work_order_id' => $workOrder->getKey(),
                'description' => 'Défaut constaté manuellement dans l\'atelier.',
                'criterion' => 'Aspect visuel conforme',
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Visual->value,
                'detected_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('non_conformities', [
            'work_order_id' => $workOrder->getKey(),
            'created_by' => $admin->getKey(),
            'severity' => 'minor',
        ]);
    }

    public function test_an_operator_can_declare_a_manual_non_conformity_on_an_active_work_order(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();

        $this->actingAs($operator);

        Livewire::test(CreateNonConformity::class)
            ->fillForm([
                'work_order_id' => $workOrder->getKey(),
                'description' => 'Défaut constaté par un opérateur sur un OF en exécution.',
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Visual->value,
                'detected_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('non_conformities', [
            'work_order_id' => $workOrder->getKey(),
            'created_by' => $operator->getKey(),
        ]);
    }

    public function test_an_operator_cannot_declare_a_manual_non_conformity_on_a_frozen_work_order(): void
    {
        $operator = User::factory()->create();
        $completed = WorkOrder::factory()->completed()->create();

        $this->actingAs($operator);

        Livewire::test(CreateNonConformity::class)
            ->fillForm([
                'work_order_id' => $completed->getKey(),
                'description' => 'Tentative de NC sur un OF hors périmètre.',
                'severity' => NonConformitySeverity::Minor->value,
                'category' => NonConformityCategory::Other->value,
                'detected_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['work_order_id']);

        $this->assertDatabaseMissing('non_conformities', [
            'work_order_id' => $completed->getKey(),
        ]);
    }

    public function test_an_admin_can_assign_a_non_conformity_with_a_remotely_searched_responsible(): void
    {
        $admin = User::factory()->admin()->create();
        $responsible = User::factory()->create();
        $nonConformity = NonConformity::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ViewNonConformity::class, ['record' => $nonConformity->getRouteKey()])
            ->callAction('assignNonConformity', data: ['responsible_id' => $responsible->getKey()])
            ->assertNotified('Affectation enregistrée');

        $this->assertSame($responsible->getKey(), $nonConformity->fresh()->responsible_id);
    }

    public function test_the_responsible_filter_only_returns_matching_non_conformities(): void
    {
        $admin = User::factory()->admin()->create();
        $firstResponsible = User::factory()->create();
        $secondResponsible = User::factory()->create();
        $first = NonConformity::factory()->create(['responsible_id' => $firstResponsible->getKey()]);
        $second = NonConformity::factory()->create(['responsible_id' => $secondResponsible->getKey()]);

        $this->actingAs($admin);

        Livewire::test(ListNonConformities::class)
            ->filterTable('responsible_id', $firstResponsible->getKey())
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    }

    public function test_an_admin_can_start_a_non_conformity_from_the_view_page(): void
    {
        $admin = User::factory()->admin()->create();
        $nonConformity = NonConformity::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ViewNonConformity::class, ['record' => $nonConformity->getRouteKey()])
            ->callAction('startNonConformity', data: ['comment' => 'Prise en charge via Filament.'])
            ->assertNotified('Non-conformité prise en charge');

        $this->assertSame(NonConformityStatus::InProgress, $nonConformity->fresh()->status);
    }

    public function test_a_non_conformity_can_be_declared_from_a_failed_quality_control(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();

        $template = $this->makeActiveTemplate($admin, [['label' => 'Contrôle dimensionnel']]);
        $workOrder = WorkOrder::factory()->create();
        $this->assignRequirement($admin, $workOrder, $template);
        $this->release($workOrder);

        $control = $workOrder->fresh()->qualityControls()->sole();
        $point = $control->points()->sole();
        $result = app(QualityControlResultService::class)->record(
            $point,
            ['value_boolean' => false],
            $operator,
        );

        app(QualityControlResultService::class)->validate(
            $result,
            ['decision' => 'validated'],
            $supervisor,
        );

        $this->actingAs($admin);

        Livewire::test(QualityControlsRelationManager::class, [
            'ownerRecord' => $workOrder->fresh(),
            'pageClass' => ViewWorkOrder::class,
        ])
            ->callTableAction('declareNonConformity', $control->fresh(), data: [
                'quality_control_result_id' => $result->getKey(),
                'severity' => NonConformitySeverity::Major->value,
                'category' => NonConformityCategory::Dimensional->value,
                'detected_at' => now()->format('Y-m-d H:i:s'),
                'description' => 'Le résultat validé est non conforme.',
            ])
            ->assertNotified('Non-conformité déclarée');

        $this->assertDatabaseHas('non_conformities', [
            'work_order_id' => $workOrder->getKey(),
            'work_order_quality_control_id' => $control->getKey(),
            'quality_control_result_id' => $result->getKey(),
        ]);
    }

    public function test_operators_cannot_access_the_admin_non_conformity_resource(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get('/admin/non-conformities')
            ->assertForbidden();
    }

    public function test_an_operator_can_access_the_workshop_non_conformity_resource_for_an_active_work_order(): void
    {
        $operator = User::factory()->create();
        $workOrder = WorkOrder::factory()->status(WorkOrderStatus::Released)->create();
        $nonConformity = NonConformity::factory()->create([
            'work_order_id' => $workOrder->getKey(),
            'detected_by' => User::factory()->create()->getKey(),
            'created_by' => User::factory()->create()->getKey(),
        ]);

        $this->actingAs($operator)
            ->get('/workshop/non-conformities')
            ->assertOk()
            ->assertSee($nonConformity->reference);
    }
}
