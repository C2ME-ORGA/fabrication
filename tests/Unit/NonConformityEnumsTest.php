<?php

namespace Tests\Unit;

use App\Enums\CorrectiveActionStatus;
use App\Enums\NonConformitySeverity;
use App\Enums\NonConformityStatus;
use PHPUnit\Framework\TestCase;

class NonConformityEnumsTest extends TestCase
{
    public function test_status_transitions_match_the_documented_lifecycle(): void
    {
        $this->assertTrue(NonConformityStatus::Open->canTransitionTo(NonConformityStatus::InProgress));
        $this->assertTrue(NonConformityStatus::Open->canTransitionTo(NonConformityStatus::Cancelled));
        $this->assertTrue(NonConformityStatus::InProgress->canTransitionTo(NonConformityStatus::PendingVerification));
        $this->assertTrue(NonConformityStatus::PendingVerification->canTransitionTo(NonConformityStatus::Closed));
        $this->assertTrue(NonConformityStatus::PendingVerification->canTransitionTo(NonConformityStatus::InProgress));
        $this->assertTrue(NonConformityStatus::Closed->canTransitionTo(NonConformityStatus::InProgress));
        $this->assertFalse(NonConformityStatus::Open->canTransitionTo(NonConformityStatus::Closed));
        $this->assertFalse(NonConformityStatus::Cancelled->canTransitionTo(NonConformityStatus::InProgress));
        $this->assertTrue(NonConformityStatus::Closed->isFinal());
        $this->assertTrue(NonConformityStatus::Cancelled->isFinal());
    }

    public function test_severity_and_action_statuses_expose_their_behaviour(): void
    {
        $this->assertTrue(NonConformitySeverity::Major->requiresQualityManager());
        $this->assertTrue(NonConformitySeverity::Critical->requiresQualityManager());
        $this->assertFalse(NonConformitySeverity::Minor->requiresQualityManager());
        $this->assertCount(3, NonConformitySeverity::options());

        $this->assertTrue(CorrectiveActionStatus::Planned->isOpen());
        $this->assertTrue(CorrectiveActionStatus::InProgress->isOpen());
        $this->assertFalse(CorrectiveActionStatus::Done->isOpen());
        $this->assertTrue(CorrectiveActionStatus::Ineffective->isFinal());
        $this->assertCount(6, CorrectiveActionStatus::options());
    }
}
