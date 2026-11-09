<?php

namespace App\DTO;

readonly class BomImportRow
{
    public function __construct(
        public int $lineNumber,
        public string $mark,
        public string $designation,
        public ?string $profile,
        public ?string $steelGrade,
        public ?float $lengthMm,
        public int $quantityPlanned,
        public ?float $unitWeightKg,
        public ?string $operationGroup,
        public ?string $notes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'mark' => $this->mark,
            'designation' => $this->designation,
            'profile' => $this->profile,
            'steel_grade' => $this->steelGrade,
            'length_mm' => $this->lengthMm,
            'quantity_planned' => $this->quantityPlanned,
            'unit_weight_kg' => $this->unitWeightKg,
            'operation_group' => $this->operationGroup,
            'notes' => $this->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'mark' => $this->mark,
            'designation' => $this->designation,
            'profile' => $this->profile,
            'steel_grade' => $this->steelGrade,
            'length_mm' => $this->lengthMm,
            'quantity_planned' => $this->quantityPlanned,
            'unit_weight_kg' => $this->unitWeightKg,
            'operation_group' => $this->operationGroup,
            'notes' => $this->notes,
        ];
    }
}
