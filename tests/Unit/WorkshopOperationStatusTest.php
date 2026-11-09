<?php

namespace Tests\Unit;

use App\Enums\WorkshopOperationStatus;
use PHPUnit\Framework\TestCase;

class WorkshopOperationStatusTest extends TestCase
{
    public function test_the_transition_matrix_matches_the_fc_6_specification(): void
    {
        $expected = [
            'pending' => ['ready'],
            'ready' => ['in_progress', 'cancelled'],
            'in_progress' => ['paused', 'blocked', 'completed'],
            'paused' => ['in_progress', 'blocked', 'cancelled'],
            'blocked' => ['ready', 'pending', 'in_progress', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        foreach ($expected as $from => $targets) {
            $status = WorkshopOperationStatus::from($from);

            $this->assertSame(
                $targets,
                array_map(
                    fn (WorkshopOperationStatus $target): string => $target->value,
                    $status->allowedTransitions(),
                ),
                "Matrice de transition incorrecte pour {$from}.",
            );
        }
    }

    public function test_a_completed_or_cancelled_operation_cannot_be_reopened(): void
    {
        $this->assertTrue(WorkshopOperationStatus::Completed->isFinal());
        $this->assertTrue(WorkshopOperationStatus::Cancelled->isFinal());
        $this->assertFalse(WorkshopOperationStatus::InProgress->isFinal());
        $this->assertSame([], WorkshopOperationStatus::Completed->allowedTransitions());
    }

    public function test_only_running_or_paused_operations_can_be_timed(): void
    {
        $this->assertTrue(WorkshopOperationStatus::InProgress->canBeTimed());
        $this->assertTrue(WorkshopOperationStatus::Paused->canBeTimed());
        $this->assertFalse(WorkshopOperationStatus::Ready->canBeTimed());
        $this->assertFalse(WorkshopOperationStatus::Blocked->canBeTimed());
        $this->assertFalse(WorkshopOperationStatus::Completed->canBeTimed());
    }
}
