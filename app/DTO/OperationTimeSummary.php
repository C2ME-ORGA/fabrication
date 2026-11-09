<?php

namespace App\DTO;

class OperationTimeSummary
{
    /**
     * @param  array<int, int>  $byOperator
     */
    public function __construct(
        public readonly int $operationId,
        public readonly int $realSeconds,
        public readonly int $estimatedSeconds,
        public readonly int $varianceSeconds,
        public readonly ?float $consumptionPercent,
        public readonly int $calendarSeconds,
        public readonly array $byOperator,
        public readonly int $entryCount,
    ) {}
}
