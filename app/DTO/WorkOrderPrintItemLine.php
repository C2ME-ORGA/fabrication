<?php

namespace App\DTO;

/**
 * Ligne de pièce du document OF imprimé.
 */
final readonly class WorkOrderPrintItemLine
{
    public function __construct(
        public string $mark,
        public string $designation,
        public ?string $profile,
        public ?string $steelGrade,
        public ?string $lengthMm,
        public int $quantityPlanned,
        public int $quantityProduced,
        public int $quantityRejected,
        public int $quantityAccepted,
        public int $quantityRemaining,
        public ?string $totalWeightKg,
        public ?string $operationGroup,
        public string $statusLabel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mark' => $this->mark,
            'designation' => $this->designation,
            'profile' => $this->profile,
            'steel_grade' => $this->steelGrade,
            'length_mm' => $this->lengthMm,
            'quantity_planned' => $this->quantityPlanned,
            'quantity_produced' => $this->quantityProduced,
            'quantity_rejected' => $this->quantityRejected,
            'quantity_accepted' => $this->quantityAccepted,
            'quantity_remaining' => $this->quantityRemaining,
            'total_weight_kg' => $this->totalWeightKg,
            'operation_group' => $this->operationGroup,
            'status' => $this->statusLabel,
        ];
    }
}
