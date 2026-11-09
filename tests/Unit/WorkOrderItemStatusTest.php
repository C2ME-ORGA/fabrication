<?php

namespace Tests\Unit;

use App\Enums\WorkOrderItemStatus;
use PHPUnit\Framework\TestCase;

class WorkOrderItemStatusTest extends TestCase
{
    public function test_every_status_has_a_label_and_a_color(): void
    {
        foreach (WorkOrderItemStatus::cases() as $status) {
            $this->assertNotSame('', $status->getLabel());
            $this->assertNotSame('', $status->getColor());
        }
    }

    public function test_options_expose_all_six_statuses(): void
    {
        $options = WorkOrderItemStatus::options();

        $this->assertCount(6, $options);
        $this->assertSame('À fabriquer', $options[WorkOrderItemStatus::Todo->value]);
        $this->assertSame('Clôturée', $options[WorkOrderItemStatus::Closed->value]);
    }

    public function test_production_states_can_move_towards_a_produced_state(): void
    {
        $this->assertTrue(WorkOrderItemStatus::Todo->canTransitionTo(WorkOrderItemStatus::InProgress));
        $this->assertTrue(WorkOrderItemStatus::Todo->canTransitionTo(WorkOrderItemStatus::PartiallyProduced));
        $this->assertTrue(WorkOrderItemStatus::Todo->canTransitionTo(WorkOrderItemStatus::Produced));
        $this->assertTrue(WorkOrderItemStatus::InProgress->canTransitionTo(WorkOrderItemStatus::PartiallyProduced));
        $this->assertTrue(WorkOrderItemStatus::PartiallyProduced->canTransitionTo(WorkOrderItemStatus::Produced));
    }

    public function test_a_produced_item_requires_an_explicit_close_or_cancel_action(): void
    {
        $this->assertTrue(WorkOrderItemStatus::Produced->canTransitionTo(WorkOrderItemStatus::Closed));
        $this->assertTrue(WorkOrderItemStatus::Produced->canTransitionTo(WorkOrderItemStatus::Cancelled));
    }

    public function test_a_quantity_correction_may_move_a_status_backwards(): void
    {
        $this->assertTrue(WorkOrderItemStatus::Produced->canTransitionTo(WorkOrderItemStatus::PartiallyProduced));
        $this->assertTrue(WorkOrderItemStatus::PartiallyProduced->canTransitionTo(WorkOrderItemStatus::InProgress));
    }

    public function test_closed_and_cancelled_are_final(): void
    {
        $this->assertTrue(WorkOrderItemStatus::Closed->isFinal());
        $this->assertTrue(WorkOrderItemStatus::Cancelled->isFinal());
        $this->assertFalse(WorkOrderItemStatus::Produced->isFinal());

        $this->assertSame([], WorkOrderItemStatus::Closed->allowedTransitions());
        $this->assertSame([], WorkOrderItemStatus::Cancelled->allowedTransitions());
    }
}
