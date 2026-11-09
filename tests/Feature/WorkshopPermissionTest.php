<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkshopPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workshop_supervisor_flag_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->update(['is_workshop_supervisor' => true]);

        $this->assertFalse($user->fresh()->isWorkshopSupervisor());
    }

    public function test_a_workshop_supervisor_does_not_gain_admin_access(): void
    {
        $user = User::factory()->workshopSupervisor()->create();
        $adminPanel = Filament::getPanel('admin');
        $workshopPanel = Panel::make()->id('workshop');

        $this->assertFalse($user->canAccessPanel($adminPanel));
        $this->assertTrue($user->canAccessPanel($workshopPanel));
        $this->assertTrue($user->canManageWorkshopRoutings());
        $this->assertTrue($user->canUnblockWorkshopOperations());
        $this->assertTrue($user->canManageOperationTimes());
    }

    public function test_an_administrator_keeps_admin_access_and_can_access_the_workshop(): void
    {
        $admin = User::factory()->admin()->create();
        $adminPanel = Filament::getPanel('admin');
        $workshopPanel = Panel::make()->id('workshop');

        $this->assertTrue($admin->canAccessPanel($adminPanel));
        $this->assertTrue($admin->canAccessPanel($workshopPanel));
        $this->assertTrue($admin->canManageWorkshopRoutings());
    }

    public function test_a_standard_operator_can_access_the_workshop_but_not_the_admin_panel(): void
    {
        $operator = User::factory()->create();
        $adminPanel = Filament::getPanel('admin');
        $workshopPanel = Panel::make()->id('workshop');

        $this->assertFalse($operator->canAccessPanel($adminPanel));
        $this->assertTrue($operator->canAccessPanel($workshopPanel));
        $this->assertFalse($operator->canManageWorkshopRoutings());
        $this->assertFalse($operator->canUnblockWorkshopOperations());
        $this->assertFalse($operator->canManageOperationTimes());
    }
}
