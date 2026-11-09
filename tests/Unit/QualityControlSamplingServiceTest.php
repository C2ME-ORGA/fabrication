<?php

namespace Tests\Unit;

use App\Enums\QualityControlSamplingMode;
use App\Enums\QualityRequirementScope;
use App\Services\QualityControlSamplingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QualityControlSamplingServiceTest extends TestCase
{
    protected QualityControlSamplingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QualityControlSamplingService;
    }

    public function test_all_mode_requires_the_number_of_applicable_items(): void
    {
        $this->assertSame(12, $this->service->computeRequiredResults(
            QualityControlSamplingMode::All,
            null,
            1,
            12,
        ));

        $this->assertSame(1, $this->service->computeRequiredResults(
            QualityControlSamplingMode::All,
            null,
            1,
            0,
        ));
    }

    public function test_every_n_mode_rounds_up(): void
    {
        $this->assertSame(3, $this->service->computeRequiredResults(
            QualityControlSamplingMode::EveryN,
            5,
            1,
            12,
        ));

        $this->assertSame(2, $this->service->computeRequiredResults(
            QualityControlSamplingMode::EveryN,
            5,
            1,
            6,
        ));
    }

    public function test_fixed_mode_uses_the_configured_count(): void
    {
        $this->assertSame(4, $this->service->computeRequiredResults(
            QualityControlSamplingMode::Fixed,
            null,
            4,
            50,
        ));
    }

    public function test_every_n_requires_a_frequency_greater_than_one(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertValidPointConfiguration(QualityControlSamplingMode::EveryN, 1, 1);
    }

    public function test_sampling_every_is_forbidden_outside_every_n(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertValidPointConfiguration(QualityControlSamplingMode::All, 5, 1);
    }

    public function test_frequency_is_forbidden_on_operation_scope(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertScopeSupportsMode(
            QualityRequirementScope::Operation,
            QualityControlSamplingMode::EveryN,
        );
    }

    public function test_frequency_is_allowed_on_item_scope(): void
    {
        $this->service->assertScopeSupportsMode(
            QualityRequirementScope::Item,
            QualityControlSamplingMode::EveryN,
        );

        $this->addToAssertionCount(1);
    }
}
