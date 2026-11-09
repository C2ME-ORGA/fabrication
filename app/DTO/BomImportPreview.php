<?php

namespace App\DTO;

readonly class BomImportPreview
{
    /**
     * @param  array<int, BomImportRow>  $rows
     * @param  array<int, BomImportError>  $errors
     * @param  array<int, BomImportPlanRow>  $plans
     * @param  array{columns: array<string, string>, ignored: array<int, string>}  $columnMapping
     */
    public function __construct(
        public string $originalFilename,
        public string $sha256,
        public int $sizeBytes,
        public string $delimiter,
        public array $columnMapping,
        public int $rowsTotal,
        public int $rowsValid,
        public int $rowsInvalid,
        public array $rows,
        public array $errors,
        public array $plans,
        public int $newItemsCount,
        public int $mergedRowsCount,
        public int $plannedQuantityAdded,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_filename' => $this->originalFilename,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'delimiter' => $this->delimiter === "\t" ? 'tabulation' : $this->delimiter,
            'column_mapping' => $this->columnMapping,
            'rows_total' => $this->rowsTotal,
            'rows_valid' => $this->rowsValid,
            'rows_invalid' => $this->rowsInvalid,
            'rows' => array_map(fn (BomImportRow $row): array => $row->toArray(), $this->rows),
            'errors' => array_map(fn (BomImportError $error): array => $error->toArray(), $this->errors),
            'plans' => array_map(fn (BomImportPlanRow $plan): array => $plan->toArray(), $this->plans),
            'new_items_count' => $this->newItemsCount,
            'merged_rows_count' => $this->mergedRowsCount,
            'planned_quantity_added' => $this->plannedQuantityAdded,
            'valid' => $this->isValid(),
        ];
    }
}
