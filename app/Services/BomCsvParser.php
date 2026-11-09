<?php

namespace App\Services;

use App\DTO\BomImportError;
use App\DTO\BomImportRow;
use App\DTO\BomParseResult;
use App\Models\WorkOrderItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lecture et validation stricte d'un fichier CSV de nomenclature.
 *
 * Le parser ne touche jamais à la base : il produit des lignes valides ou
 * une liste d'erreurs avec numéro de ligne, colonne et valeur.
 */
class BomCsvParser
{
    protected const CANONICAL_COLUMNS = [
        'mark',
        'designation',
        'profile',
        'steel_grade',
        'length_mm',
        'quantity_planned',
        'unit_weight_kg',
        'operation_group',
        'notes',
    ];

    public function __construct(
        protected BomImportStorageService $storage,
    ) {}

    public function parse(UploadedFile $file): BomParseResult
    {
        try {
            $this->storage->validate($file);
        } catch (ValidationException $exception) {
            return BomParseResult::failed(new BomImportError(
                null,
                'file',
                null,
                (string) collect($exception->errors())->flatten()->first(),
            ));
        }

        $realPath = $file->getRealPath();
        $content = is_string($realPath) ? file_get_contents($realPath) : false;

        if (! is_string($content) || $content === '') {
            return BomParseResult::failed(new BomImportError(null, 'file', null, 'Le fichier est vide ou illisible.'));
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return BomParseResult::failed(new BomImportError(
                null,
                'file',
                null,
                'Le fichier doit être encodé en UTF-8. Les encodages UTF-16 ne sont pas acceptés.',
            ));
        }

        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            return BomParseResult::failed(new BomImportError(
                null,
                'file',
                null,
                'Le fichier doit être encodé en UTF-8 valide.',
            ));
        }

        $delimiter = $this->detectDelimiter($content);

        if ($delimiter === null) {
            return BomParseResult::failed(new BomImportError(
                null,
                'file',
                null,
                'Le séparateur CSV est introuvable. Utilisez une virgule, un point-virgule ou une tabulation.',
            ));
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return BomParseResult::failed(new BomImportError(null, 'file', null, 'Le fichier ne peut pas être analysé.'));
        }

        fwrite($stream, $content);
        rewind($stream);

        try {
            return $this->parseStream($stream, $delimiter);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    protected function parseStream($stream, string $delimiter): BomParseResult
    {
        $lineNumber = 0;
        $headerRow = $this->readNonEmptyRow($stream, $delimiter, $lineNumber);

        if ($headerRow === null) {
            return BomParseResult::failed(new BomImportError(null, 'file', null, 'Le fichier CSV est vide.'));
        }

        [$columnIndexes, $columnMapping, $headerErrors] = $this->analyzeHeader($headerRow, $lineNumber);

        if ($headerErrors !== []) {
            return new BomParseResult(
                rows: [],
                errors: $headerErrors,
                delimiter: $delimiter,
                columnMapping: $columnMapping,
                rowsTotal: 0,
                rowsValid: 0,
                rowsInvalid: 0,
            );
        }

        $rows = [];
        $errors = [];
        $rowsTotal = 0;
        $rowsValid = 0;
        $rowsInvalid = 0;
        $maxRows = (int) config('bom.max_rows', 5000);
        $headerCount = count($headerRow);

        while (($row = $this->readNonEmptyRow($stream, $delimiter, $lineNumber)) !== null) {
            $rowsTotal++;

            if ($rowsTotal > $maxRows) {
                $errors[] = new BomImportError(
                    null,
                    null,
                    null,
                    "Le fichier dépasse le nombre maximal de {$maxRows} lignes.",
                );

                break;
            }

            [$parsedRow, $rowErrors] = $this->parseRow($row, $headerCount, $columnIndexes, $lineNumber);

            if ($rowErrors !== []) {
                $rowsInvalid++;
                $errors = [...$errors, ...$rowErrors];

                continue;
            }

            $rowsValid++;
            $rows[] = $parsedRow;
        }

        if ($rowsTotal === 0) {
            $errors[] = new BomImportError(
                null,
                null,
                null,
                'Le fichier ne contient aucune ligne de données.',
            );
        }

        return new BomParseResult(
            rows: $rows,
            errors: $errors,
            delimiter: $delimiter,
            columnMapping: $columnMapping,
            rowsTotal: $rowsTotal,
            rowsValid: $rowsValid,
            rowsInvalid: $rowsInvalid,
        );
    }

    /**
     * @param  array<int, string|null>  $headerRow
     * @return array{0: array<string, int>, 1: array{columns: array<string, string>, ignored: array<int, string>}, 2: array<int, BomImportError>}
     */
    protected function analyzeHeader(array $headerRow, int $lineNumber): array
    {
        $canonicalColumns = self::CANONICAL_COLUMNS;
        $forbiddenColumns = (array) config('bom.forbidden_columns', []);
        $requiredColumns = (array) config('bom.required_columns', []);
        $aliases = (array) config('bom.column_aliases', []);

        $columnIndexes = [];
        $columns = [];
        $ignored = [];
        $errors = [];

        foreach ($headerRow as $index => $rawHeader) {
            $source = trim((string) $rawHeader);
            $normalized = $this->normalizeHeader($source);

            if ($normalized === '') {
                if ($source !== '') {
                    $ignored[] = $source;
                }

                continue;
            }

            $canonical = in_array($normalized, $forbiddenColumns, true)
                ? $normalized
                : (in_array($normalized, $canonicalColumns, true)
                    ? $normalized
                    : ($aliases[$normalized] ?? null));

            if ($canonical === null) {
                $ignored[] = $source;

                continue;
            }

            if (in_array($canonical, $forbiddenColumns, true)) {
                $errors[] = new BomImportError(
                    $lineNumber,
                    $canonical,
                    $source,
                    "La colonne {$canonical} est interdite à l'import.",
                );

                continue;
            }

            if (array_key_exists($canonical, $columns)) {
                $errors[] = new BomImportError(
                    $lineNumber,
                    $canonical,
                    $source,
                    "La colonne {$canonical} est présente plusieurs fois.",
                );

                continue;
            }

            $columnIndexes[$canonical] = $index;
            $columns[$canonical] = $source;
        }

        foreach ($requiredColumns as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columns)) {
                $errors[] = new BomImportError(
                    $lineNumber,
                    $requiredColumn,
                    null,
                    "La colonne obligatoire {$requiredColumn} est absente.",
                );
            }
        }

        return [
            $columnIndexes,
            ['columns' => $columns, 'ignored' => $ignored],
            $errors,
        ];
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $columnIndexes
     * @return array{0: BomImportRow, 1: array<int, BomImportError>}
     */
    protected function parseRow(array $row, int $headerCount, array $columnIndexes, int $lineNumber): array
    {
        $errors = [];

        if (count($row) > $headerCount) {
            $errors[] = new BomImportError(
                $lineNumber,
                null,
                null,
                "La ligne contient plus de colonnes que l'en-tête.",
            );
        }

        $value = function (string $column) use ($row, $columnIndexes): ?string {
            if (! array_key_exists($column, $columnIndexes)) {
                return null;
            }

            $raw = $row[$columnIndexes[$column]] ?? null;

            if ($raw === null) {
                return null;
            }

            return trim((string) $raw);
        };

        $markRaw = $value('mark');
        $mark = $markRaw === null || $markRaw === '' ? '' : WorkOrderItem::normalizeMark($markRaw);

        if ($mark === '') {
            $errors[] = new BomImportError($lineNumber, 'mark', $markRaw, 'Le repère est obligatoire.');
        } elseif (Str::length($mark) > 100) {
            $errors[] = new BomImportError($lineNumber, 'mark', $mark, 'Le repère ne peut pas dépasser 100 caractères.');
        }

        $designation = $value('designation');

        if ($designation === null || $designation === '') {
            $errors[] = new BomImportError($lineNumber, 'designation', $designation, 'La désignation est obligatoire.');
        } elseif (Str::length($designation) > 255) {
            $errors[] = new BomImportError($lineNumber, 'designation', $designation, 'La désignation ne peut pas dépasser 255 caractères.');
        }

        $profile = $this->nullableText($value('profile'), 'profile', 100, $lineNumber, $errors);
        $steelGrade = $this->nullableText($value('steel_grade'), 'steel_grade', 100, $lineNumber, $errors);
        $operationGroup = $this->nullableText($value('operation_group'), 'operation_group', 100, $lineNumber, $errors);
        $notes = $this->nullableText($value('notes'), 'notes', null, $lineNumber, $errors);

        $quantityRaw = $value('quantity_planned');
        $quantityPlanned = $this->parseInteger($quantityRaw, 'quantity_planned', $lineNumber, $errors);

        $lengthMm = $this->parseDecimal(
            $value('length_mm'),
            'length_mm',
            9999999999.99,
            $lineNumber,
            $errors,
        );

        $unitWeightKg = $this->parseDecimal(
            $value('unit_weight_kg'),
            'unit_weight_kg',
            999999999.999,
            $lineNumber,
            $errors,
        );

        return [
            new BomImportRow(
                lineNumber: $lineNumber,
                mark: $mark,
                designation: $designation ?? '',
                profile: $profile,
                steelGrade: $steelGrade,
                lengthMm: $lengthMm,
                quantityPlanned: $quantityPlanned ?? 0,
                unitWeightKg: $unitWeightKg,
                operationGroup: $operationGroup,
                notes: $notes,
            ),
            $errors,
        ];
    }

    /**
     * @param  array<int, BomImportError>  $errors
     */
    protected function nullableText(?string $value, string $column, ?int $maxLength, int $lineNumber, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($maxLength !== null && Str::length($value) > $maxLength) {
            $errors[] = new BomImportError(
                $lineNumber,
                $column,
                $value,
                "La colonne {$column} ne peut pas dépasser {$maxLength} caractères.",
            );
        }

        return $value;
    }

    /**
     * @param  array<int, BomImportError>  $errors
     */
    protected function parseInteger(?string $value, string $column, int $lineNumber, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            $errors[] = new BomImportError($lineNumber, $column, $value, "La colonne {$column} est obligatoire.");

            return null;
        }

        $normalized = str_replace([' ', "\u{00A0}"], '', $value);

        if (! preg_match('/^\d+$/', $normalized)) {
            $errors[] = new BomImportError($lineNumber, $column, $value, "La colonne {$column} doit être un entier positif ou nul.");

            return null;
        }

        $integer = (int) $normalized;

        if ($integer > 4294967295) {
            $errors[] = new BomImportError($lineNumber, $column, $value, "La colonne {$column} dépasse la valeur maximale autorisée.");

            return null;
        }

        return $integer;
    }

    /**
     * @param  array<int, BomImportError>  $errors
     */
    protected function parseDecimal(?string $value, string $column, float $max, int $lineNumber, array &$errors): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([' ', "\u{00A0}"], '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            $errors[] = new BomImportError($lineNumber, $column, $value, "La colonne {$column} doit être numérique.");

            return null;
        }

        $decimal = (float) $normalized;

        if ($decimal < 0) {
            $errors[] = new BomImportError($lineNumber, $column, $value, "La colonne {$column} ne peut pas être négative.");

            return null;
        }

        if ($decimal > $max) {
            $errors[] = new BomImportError($lineNumber, $column, $value, "La colonne {$column} dépasse la valeur maximale autorisée.");

            return null;
        }

        return $decimal;
    }

    protected function normalizeHeader(string $header): string
    {
        $header = Str::ascii(trim($header));
        $header = Str::lower($header);
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';

        return trim($header, '_');
    }

    protected function detectDelimiter(string $content): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $firstLine = null;

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $firstLine = $line;

                break;
            }
        }

        if ($firstLine === null) {
            return null;
        }

        $bestDelimiter = null;
        $bestCount = 1;

        foreach ((array) config('bom.delimiters', [',', ';', "\t"]) as $delimiter) {
            $columns = str_getcsv($firstLine, $delimiter, '"', '');
            $count = count($columns);

            if ($count > $bestCount) {
                $bestDelimiter = $delimiter;
                $bestCount = $count;
            }
        }

        return $bestDelimiter;
    }

    /**
     * @param  resource  $stream
     * @return array<int, string|null>|null
     */
    protected function readNonEmptyRow($stream, string $delimiter, int &$lineNumber): ?array
    {
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $lineNumber++;

            if (! $this->isBlankRow($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    protected function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
