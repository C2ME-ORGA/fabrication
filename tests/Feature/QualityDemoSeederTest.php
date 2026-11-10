<?php

namespace Tests\Feature;

use App\Models\QualityControlTemplate;
use App\Models\WorkshopRouting;
use Database\Seeders\QualityDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_creates_an_active_template_and_a_routing_requirement(): void
    {
        $this->seed(QualityDemoSeeder::class);
        $this->seed(QualityDemoSeeder::class);

        $template = QualityControlTemplate::query()->where('code', 'QUAL-DEMO-01')->sole();

        $this->assertTrue($template->is_active);
        $this->assertNotNull($template->activeVersion());
        $this->assertSame(4, $template->activeVersion()->points()->count());

        $routing = WorkshopRouting::query()->where('code', 'GAM-QUAL-DEMO')->sole();
        $this->assertTrue($routing->is_active);
        $this->assertSame(2, $routing->operations()->count());
        $this->assertTrue(
            $routing->operations()->first()->qualityRequirements()->exists(),
            'La gamme de démonstration doit porter une exigence qualité.',
        );
    }
}
