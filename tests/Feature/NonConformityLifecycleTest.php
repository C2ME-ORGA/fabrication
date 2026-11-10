<?php

namespace Tests\Feature;

use App\Enums\CorrectiveActionStatus;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use App\Enums\NonConformityVerificationResult;
use App\Enums\NonConformityVerificationType;
use App\Models\NonConformity;
use App\Models\NonConformityCorrectiveAction;
use App\Models\NonConformityStatusHistory;
use App\Models\NonConformityVerification;
use App\Models\User;
use App\Services\CorrectiveActionService;
use App\Services\NonConformityLifecycleService;
use App\Services\NonConformityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class NonConformityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisor;

    protected User $qualityManager;

    protected NonConformityLifecycleService $lifecycle;

    protected CorrectiveActionService $actions;

    protected NonConformityVerificationService $verifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->supervisor = User::factory()->workshopSupervisor()->create();
        $this->qualityManager = User::factory()->qualityManager()->create();
        $this->lifecycle = app(NonConformityLifecycleService::class);
        $this->actions = app(CorrectiveActionService::class);
        $this->verifications = app(NonConformityVerificationService::class);
    }

    public function test_open_to_in_progress_and_invalid_transitions_are_controlled(): void
    {
        $nonConformity = NonConformity::factory()->create();

        $started = $this->lifecycle->start($nonConformity, $this->supervisor, 'Prise en charge.');

        $this->assertSame(NonConformityStatus::InProgress, $started->status);
        $this->assertNotNull($started->taken_in_charge_at);
        $this->assertSame(1, $started->statusHistories()->count());

        $this->expectException(ValidationException::class);
        $this->lifecycle->close($started, $this->supervisor, 'Clôture prématurée.');
    }

    public function test_pending_verification_requires_a_completed_corrective_action(): void
    {
        $nonConformity = NonConformity::factory()->status(NonConformityStatus::InProgress)->create();

        try {
            $this->lifecycle->submitForVerification($nonConformity, $this->supervisor);
            $this->fail('Une NC sans action terminée ne doit pas passer en vérification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->actions->add($nonConformity, [
            'description' => 'Reprendre la pièce.',
        ], $this->supervisor);

        $action = $nonConformity->correctiveActions()->sole();
        $this->actions->start($action, $this->supervisor);

        try {
            $this->actions->complete($action->fresh(), $this->supervisor, '');
            $this->fail('Une action terminée sans preuve doit être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_comment', $exception->errors());
        }

        $this->actions->complete($action->fresh(), $this->supervisor, 'Contrôle après reprise conforme.');

        $pending = $this->lifecycle->submitForVerification($nonConformity->fresh(), $this->supervisor);

        $this->assertSame(NonConformityStatus::PendingVerification, $pending->status);
        $this->assertNotNull($pending->treated_at);
    }

    public function test_a_minor_non_conformity_can_be_closed_by_a_supervisor_without_verification(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Minor);

        $closed = $this->lifecycle->close($nonConformity, $this->supervisor, 'Défaut mineur corrigé et accepté.');

        $this->assertSame(NonConformityStatus::Closed, $closed->status);
        $this->assertSame($this->supervisor->getKey(), $closed->closed_by);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_closed',
        ]);
    }

    public function test_a_major_non_conformity_requires_a_quality_verification_and_a_quality_manager(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Major);

        try {
            $this->lifecycle->close($nonConformity, $this->supervisor, 'Tentative de clôture atelier.');
            $this->fail('Un responsable atelier ne doit pas clôturer une NC majeure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('non_conformity', $exception->errors());
        }

        try {
            $this->lifecycle->close($nonConformity, $this->qualityManager, 'Clôture sans vérification.');
            $this->fail('Une vérification conforme est nécessaire.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification', $exception->errors());
        }

        $this->verifications->record($nonConformity, [
            'type' => NonConformityVerificationType::ClosureReview->value,
            'result' => NonConformityVerificationResult::Conforming->value,
            'comment' => 'Revue de clôture conforme.',
        ], $this->qualityManager);

        $closed = $this->lifecycle->close($nonConformity->fresh(), $this->qualityManager, 'Clôture après revue conforme.');

        $this->assertTrue($closed->isClosed());
    }

    public function test_a_critical_non_conformity_requires_counter_control_and_effectiveness(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Critical);

        $this->verifications->record($nonConformity, [
            'type' => NonConformityVerificationType::CounterControl->value,
            'result' => NonConformityVerificationResult::Conforming->value,
            'comment' => 'Contre-contrôle conforme.',
        ], $this->qualityManager);

        try {
            $this->lifecycle->close($nonConformity->fresh(), $this->qualityManager, 'Clôture incomplète.');
            $this->fail('Une vérification d\'efficacité est également nécessaire.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification', $exception->errors());
        }

        $this->verifications->record($nonConformity, [
            'type' => NonConformityVerificationType::Effectiveness->value,
            'result' => NonConformityVerificationResult::Conforming->value,
            'comment' => 'Efficacité confirmée.',
        ], $this->qualityManager);

        $this->assertSame(
            CorrectiveActionStatus::Verified,
            $nonConformity->correctiveActions()->sole()->status,
        );

        $closed = $this->lifecycle->close($nonConformity->fresh(), $this->qualityManager, 'Clôture critique conforme.');

        $this->assertTrue($closed->isClosed());
    }

    public function test_a_non_conforming_verification_returns_the_non_conformity_to_treatment(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Major);

        $this->verifications->record($nonConformity, [
            'type' => NonConformityVerificationType::Effectiveness->value,
            'result' => NonConformityVerificationResult::NonConforming->value,
            'comment' => 'Le défaut réapparaît.',
        ], $this->qualityManager);

        $this->assertSame(NonConformityStatus::InProgress, $nonConformity->fresh()->status);
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_status_changed',
            'to_status' => NonConformityStatus::InProgress->value,
        ]);
    }

    public function test_a_declarer_cannot_verify_a_major_or_critical_non_conformity(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Major, $this->qualityManager);

        $this->expectException(ValidationException::class);

        $this->verifications->record($nonConformity, [
            'type' => NonConformityVerificationType::Effectiveness->value,
            'result' => NonConformityVerificationResult::Conforming->value,
            'comment' => 'Auto-vérification interdite.',
        ], $this->qualityManager);
    }

    public function test_an_ineffective_action_returns_a_pending_non_conformity_to_treatment(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Major);

        $action = $nonConformity->correctiveActions()->sole();

        $this->actions->markIneffective($action, $this->qualityManager, 'La cause n\'est pas traitée.');

        $this->assertSame(CorrectiveActionStatus::Ineffective, $action->fresh()->status);
        $this->assertSame(NonConformityStatus::InProgress, $nonConformity->fresh()->status);
    }

    public function test_reopening_a_closed_non_conformity_keeps_the_history(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Minor);
        $closed = $this->lifecycle->close($nonConformity, $this->supervisor, 'Clôture initiale.');

        $historyBefore = $closed->statusHistories()->count();

        $reopened = $this->lifecycle->reopen($closed, $this->qualityManager, 'Récidive constatée.');

        $this->assertSame(NonConformityStatus::InProgress, $reopened->status);
        $this->assertNull($reopened->closed_at);
        $this->assertSame($historyBefore + 1, $reopened->statusHistories()->count());
        $this->assertDatabaseHas('quality_audit_logs', [
            'subject_type' => 'non_conformity',
            'subject_id' => $nonConformity->getKey(),
            'event' => 'non_conformity_reopened',
        ]);
    }

    public function test_a_cancelled_non_conformity_cannot_be_reopened(): void
    {
        $nonConformity = NonConformity::factory()
            ->status(NonConformityStatus::Cancelled)
            ->create();

        $this->expectException(ValidationException::class);

        $this->lifecycle->reopen($nonConformity, $this->qualityManager, 'Tentative de réouverture interdite.');
    }

    public function test_history_and_verifications_are_append_only(): void
    {
        $nonConformity = $this->pendingNonConformity(NonConformitySeverity::Major);

        $history = $nonConformity->statusHistories()->first();
        $verification = NonConformityVerification::factory()->create([
            'non_conformity_id' => $nonConformity->getKey(),
        ]);

        foreach ([$history, $verification] as $model) {
            try {
                $model->comment = 'modification interdite';
                $model->save();
                $this->fail('La modification aurait dû être refusée.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }

            try {
                $model->delete();
                $this->fail('La suppression aurait dû être refusée.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseHas('non_conformity_status_histories', ['id' => $history->getKey()]);
        $this->assertDatabaseHas('non_conformity_verifications', ['id' => $verification->getKey()]);
    }

    protected function pendingNonConformity(
        NonConformitySeverity $severity,
        ?User $declarer = null,
    ): NonConformity {
        $nonConformity = NonConformity::factory()
            ->severity($severity)
            ->status(NonConformityStatus::PendingVerification)
            ->create([
                'detected_by' => ($declarer ?? User::factory()->create())->getKey(),
                'created_by' => ($declarer ?? User::factory()->create())->getKey(),
                'treated_at' => now(),
            ]);

        NonConformityCorrectiveAction::factory()->create([
            'non_conformity_id' => $nonConformity->getKey(),
            'status' => CorrectiveActionStatus::Done,
            'completed_at' => now(),
            'completed_by' => $this->supervisor->getKey(),
            'evidence_comment' => 'Preuve de réalisation.',
        ]);

        NonConformityStatusHistory::factory()->create([
            'non_conformity_id' => $nonConformity->getKey(),
            'from_status' => NonConformityStatus::InProgress,
            'to_status' => NonConformityStatus::PendingVerification,
            'actor_id' => $this->supervisor->getKey(),
        ]);

        return $nonConformity->refresh();
    }
}
