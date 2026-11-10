<?php

namespace Tests\Feature;

use App\Enums\QualityControlType;
use App\Filament\Resources\QualityControlTemplates\Pages\CreateQualityControlTemplate;
use App\Models\QualityControlTemplate;
use App\Models\User;
use App\Services\QualityControlTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QualityControlFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quality_manager_can_access_the_referential_in_the_workshop_panel(): void
    {
        $quality = User::factory()->qualityManager()->create();

        $this->actingAs($quality)
            ->get('/workshop/quality-control-templates')
            ->assertOk();

        $this->actingAs($quality)
            ->get('/workshop/quality-control-templates/create')
            ->assertOk();
    }

    public function test_operators_and_workshop_supervisors_cannot_access_the_referential(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();

        foreach ([$operator, $supervisor] as $user) {
            $this->actingAs($user)
                ->get('/workshop/quality-control-templates')
                ->assertForbidden();
        }
    }

    public function test_an_administrator_can_access_the_referential_in_the_admin_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/quality-control-templates')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/admin/quality-control-templates/create')
            ->assertOk();
    }

    public function test_an_admin_can_create_a_template_from_the_filament_form(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreateQualityControlTemplate::class)
            ->fillForm([
                'code' => 'dim-ui-01',
                'name' => 'Dimensionnel UI',
                'control_type' => QualityControlType::Dimensional->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = QualityControlTemplate::query()->where('code', 'DIM-UI-01')->firstOrFail();

        $this->assertSame('Dimensionnel UI', $template->name);
        $this->assertFalse($template->is_active);
        $this->assertSame(1, $template->versions()->count());
        $this->assertTrue($template->draftVersion()->isDraft());
    }

    public function test_the_version_page_exposes_the_points_relation_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $actor = User::factory()->qualityManager()->create();

        $template = app(QualityControlTemplateService::class)->create([
            'code' => 'VER-UI-01',
            'name' => 'Version UI',
            'control_type' => QualityControlType::Visual->value,
        ], $actor);

        $version = $template->draftVersion();

        $this->actingAs($admin)
            ->get("/admin/quality-control-template-versions/{$version->getKey()}")
            ->assertOk()
            ->assertSee('Points de contrôle');
    }
}
