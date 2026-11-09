<?php

namespace App\DTO;

readonly class BomParseResult
{
    /**
     * @param  array<int, BomImportRow>  $rows
     * @param  array<int, BomImportError>  $errors
     * @param  array{columns: array<string, string>, ignored: array<int, string>}  $columnMapping
     */
    public function __construct(
        public array $rows,
        public array $errors,
        public string $delimiter,
        public array $columnMapping,
        public int $rowsTotal,
        public int $rowsValid,
        public int $rowsInvalid,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public static function failed(BomImportError $error): self
    {
        return new self(
            rows: [],
            errors: [$error],
            delimiter: '',
            columnMapping: ['columns' => [], 'ignored' => []],
            rowsTotal: 0,
            rowsValid: 0,
            rowsInvalid: 0,
        );
    }
}
