<?php

namespace Tests\Feature;

use App\Models\QualityDeviation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityDeviationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_quality_managers_and_administrators_can_manage_deviations(): void
    {
        $operator = User::factory()->create();
        $supervisor = User::factory()->workshopSupervisor()->create();
        $qualityManager = User::factory()->qualityManager()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$operator, $supervisor] as $user) {
            $this->assertFalse($user->can('create', QualityDeviation::class));
            $this->assertFalse($user->can('viewAny', QualityDeviation::class));
        }

        foreach ([$qualityManager, $admin] as $user) {
            $this->assertTrue($user->can('create', QualityDeviation::class));
            $this->assertTrue($user->can('viewAny', QualityDeviation::class));
        }
    }

    public function test_pending_deviations_can_be_approved_or_rejected_by_quality_profiles(): void
    {
        $supervisor = User::factory()->workshopSupervisor()->create();
        $qualityManager = User::factory()->qualityManager()->create();
        $admin = User::factory()->admin()->create();
        $deviation = QualityDeviation::factory()->pending()->create();

        $this->assertTrue($qualityManager->can('approve', $deviation));
        $this->assertTrue($qualityManager->can('reject', $deviation));
        $this->assertTrue($admin->can('approve', $deviation));
        $this->assertFalse($supervisor->can('approve', $deviation));
        $this->assertFalse($supervisor->can('reject', $deviation));
    }

    public function test_only_an_active_deviation_can_be_revoked(): void
    {
        $qualityManager = User::factory()->qualityManager()->create();
        $pending = QualityDeviation::factory()->pending()->create();
        $active = QualityDeviation::factory()->active()->create();

        $this->assertFalse($qualityManager->can('revoke', $pending));
        $this->assertTrue($qualityManager->can('revoke', $active));
    }

    public function test_the_requester_cannot_approve_their_own_deviation(): void
    {
        $qualityManager = User::factory()->qualityManager()->create();
        $deviation = QualityDeviation::factory()->pending()->create([
            'requested_by' => $qualityManager->getKey(),
        ]);

        $this->assertFalse($qualityManager->can('approve', $deviation));
    }
}
