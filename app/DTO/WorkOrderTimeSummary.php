<?php

namespace App\DTO;

class WorkOrderTimeSummary
{
    /**
     * @param  array<int, int>  $byOperator
     * @param  array<int, int>  $byOperation
     */
    public function __construct(
        public readonly int $workOrderId,
        public readonly int $realSeconds,
        public readonly int $estimatedSeconds,
        public readonly int $varianceSeconds,
        public readonly ?float $consumptionPercent,
        public readonly int $calendarSeconds,
        public readonly array $byOperator,
        public readonly array $byOperation,
        public readonly int $entryCount,
    ) {}
}
