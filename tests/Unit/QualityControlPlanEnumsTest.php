<?php

namespace Tests\Unit;

use App\Enums\QualityControlStatus;
use App\Enums\QualityResultDecision;
use PHPUnit\Framework\TestCase;

class QualityControlPlanEnumsTest extends TestCase
{
    public function test_control_statuses_expose_a_label_a_color_and_options(): void
    {
        foreach (QualityControlStatus::cases() as $status) {
            $this->assertNotSame('', $status->getLabel());
            $this->assertNotSame('', $status->getColor());
            $this->assertArrayHasKey($status->value, QualityControlStatus::options());
        }

        $this->assertSame(6, count(QualityControlStatus::options()));
        $this->assertTrue(QualityControlStatus::Passed->isFinal());
        $this->assertTrue(QualityControlStatus::Cancelled->isFinal());
        $this->assertFalse(QualityControlStatus::Failed->isFinal());
        $this->assertTrue(QualityControlStatus::Failed->allowsResultEntry());
    }

    public function test_validation_decisions_expose_labels_and_options(): void
    {
        foreach (QualityResultDecision::cases() as $decision) {
            $this->assertNotSame('', $decision->getLabel());
            $this->assertArrayHasKey($decision->value, QualityResultDecision::options());
        }

        $this->assertSame(['validated', 'rejected'], array_keys(QualityResultDecision::options()));
    }
}
