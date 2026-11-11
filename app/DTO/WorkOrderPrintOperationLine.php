<?php

namespace App\DTO;

/**
 * Ligne d'opération du document OF imprimé.
 */
final readonly class WorkOrderPrintOperationLine
{
    /**
     * @param  array<int, string>  $operators
     */
    public function __construct(
        public int $position,
        public string $code,
        public string $name,
        public ?string $instructions,
        public ?int $estimatedDurationMinutes,
        public int $realSeconds,
        public string $statusLabel,
        public ?string $startedAt,
        public ?string $completedAt,
        public array $operators,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'code' => $this->code,
            'name' => $this->name,
            'instructions' => $this->instructions,
            'estimated_duration_minutes' => $this->estimatedDurationMinutes,
            'real_seconds' => $this->realSeconds,
            'status' => $this->statusLabel,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'operators' => $this->operators,
        ];
    }
}
