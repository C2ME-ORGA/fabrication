<?php

namespace App\DTO;

readonly class BomImportError
{
    public function __construct(
        public ?int $lineNumber,
        public ?string $column,
        public ?string $value,
        public string $message,
    ) {}

    /**
     * @return array{line_number: ?int, column: ?string, value: ?string, message: string}
     */
    public function toArray(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'column' => $this->column,
            'value' => $this->value,
            'message' => $this->message,
        ];
    }
}
