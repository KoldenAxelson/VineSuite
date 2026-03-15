<?php

declare(strict_types=1);

namespace App\Services\LabImport;

use App\Models\LabAnalysis;

/**
 * Parser for ETS Laboratories CSV exports.
 *
 * ETS Labs is one of the most common external wine analysis labs in California.
 * Their CSV exports typically include: Sample ID, Wine/Lot Name, Date Received,
 * Date Reported, and then columns for each test (pH, TA, VA, Free SO2, etc.).
 *
 * This parser is resilient to:
 * - Column reordering (matches by header name, not position)
 * - Extra header rows (ETS sometimes adds a title row before the column headers)
 * - Empty rows and trailing whitespace
 * - Missing optional columns
 */
class ETSLabsParser implements LabCsvParser
{
    /**
     * Column header mappings: ETS header variations → our test_type.
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'ph' => 'pH',
        'titratable acidity' => 'TA',
        'ta' => 'TA',
        'ta (g/l)' => 'TA',
        'volatile acidity' => 'VA',
        'va' => 'VA',
        'va (g/100ml)' => 'VA',
        'free so2' => 'free_SO2',
        'free sulfur dioxide' => 'free_SO2',
        'free so2 (mg/l)' => 'free_SO2',
        'total so2' => 'total_SO2',
        'total sulfur dioxide' => 'total_SO2',
        'total so2 (mg/l)' => 'total_SO2',
        'residual sugar' => 'residual_sugar',
        'rs' => 'residual_sugar',
        'rs (g/l)' => 'residual_sugar',
        'alcohol' => 'alcohol',
        'alcohol (% v/v)' => 'alcohol',
        'ethanol' => 'alcohol',
        'malic acid' => 'malic_acid',
        'malic acid (g/l)' => 'malic_acid',
        'glucose + fructose' => 'glucose_fructose',
        'glucose/fructose' => 'glucose_fructose',
        'glucose+fructose' => 'glucose_fructose',
        'turbidity' => 'turbidity',
        'turbidity (ntu)' => 'turbidity',
        'color' => 'color',
        'color intensity' => 'color',
    ];

    /**
     * ETS-specific headers that identify this as an ETS Labs export.
     * Used by canParse() — must be ETS-distinctive, not generic.
     *
     * @var array<int, string>
     */
    private const ETS_IDENTIFYING_HEADERS = [
        'wine',
        'wine name',
        'wine/lot',
        'sample',
        'sample id',
        'sample name',
    ];

    /**
     * All accepted lot/sample headers (for parsing after format is confirmed).
     *
     * @var array<int, string>
     */
    private const LOT_HEADERS = [
        'wine',
        'wine name',
        'wine/lot',
        'lot',
        'lot name',
        'lot id',
        'sample',
        'sample id',
        'sample name',
        'description',
    ];

    /**
     * Headers that identify the test/report date.
     *
     * @var array<int, string>
     */
    private const DATE_HEADERS = [
        'date received',
        'date reported',
        'date',
        'report date',
        'analysis date',
        'test date',
    ];

    public function canParse(array $rows): bool
    {
        if (count($rows) < 2) {
            return false;
        }

        // Look for ETS-specific markers in the first few rows
        $headerRow = $this->findHeaderRow($rows);

        if ($headerRow === null) {
            return false;
        }

        $normalizedHeaders = array_map(
            fn (string $h): string => strtolower(trim($h)),
            $rows[$headerRow],
        );

        // ETS exports must have an ETS-specific header ("Wine", "Sample", etc.) + 2+ test columns
        $hasEtsHeader = false;
        $testColumnCount = 0;

        foreach ($normalizedHeaders as $header) {
            if (in_array($header, self::ETS_IDENTIFYING_HEADERS, true)) {
                $hasEtsHeader = true;
            }
            if (isset(self::COLUMN_MAP[$header])) {
                $testColumnCount++;
            }
        }

        return $hasEtsHeader && $testColumnCount >= 2;
    }

    public function parse(array $rows): ParsedLabImport
    {
        $headerRowIndex = $this->findHeaderRow($rows);

        if ($headerRowIndex === null) {
            return new ParsedLabImport(
                records: [],
                warnings: ['Could not identify header row in CSV.'],
                source: $this->getSource(),
                totalRows: max(0, count($rows) - 1),
                skippedRows: max(0, count($rows) - 1),
            );
        }

        $headers = array_map(
            fn (string $h): string => strtolower(trim($h)),
            $rows[$headerRowIndex],
        );

        // Build column index maps
        $lotColIndex = $this->findColumnIndex($headers, self::LOT_HEADERS);
        $dateColIndex = $this->findColumnIndex($headers, self::DATE_HEADERS);
        $testColumns = $this->mapTestColumns($headers);

        $records = [];
        $warnings = [];
        $skipped = 0;
        $dataRows = array_slice($rows, $headerRowIndex + 1, preserve_keys: true);

        foreach ($dataRows as $rowIndex => $row) {
            $csvRowNumber = $rowIndex + 1; // 1-based for user display

            // Skip empty rows
            $joined = implode('', array_map('trim', $row));
            if ($joined === '') {
                $skipped++;

                continue;
            }

            // Extract lot name
            $lotName = $lotColIndex !== null ? trim($row[$lotColIndex] ?? '') : null;
            if ($lotName === '') {
                $lotName = null;
            }

            // Extract date
            $testDate = $this->parseDate($dateColIndex !== null ? trim($row[$dateColIndex] ?? '') : '');
            if ($testDate === null) {
                $warnings[] = "Row {$csvRowNumber}: Could not parse date, using today's date.";
                $testDate = date('Y-m-d');
            }

            // Extract each test value
            $rowHasData = false;
            foreach ($testColumns as $colIndex => $testType) {
                $rawValue = trim($row[$colIndex] ?? '');

                // Skip empty, N/A, or non-numeric values
                if ($rawValue === '' || strtolower($rawValue) === 'n/a' || strtolower($rawValue) === '-') {
                    continue;
                }

                // Handle values like "<0.01" or ">100"
                $cleanValue = ltrim($rawValue, '<>');
                if (! is_numeric($cleanValue)) {
                    $warnings[] = "Row {$csvRowNumber}: Non-numeric value '{$rawValue}' for {$testType}, skipped.";

                    continue;
                }

                $records[] = new ParsedLabRecord(
                    rowNumber: $csvRowNumber,
                    lotName: $lotName,
                    testDate: $testDate,
                    testType: $testType,
                    value: (float) $cleanValue,
                    unit: LabAnalysis::DEFAULT_UNITS[$testType] ?? '',
                    analyst: 'ETS Laboratories',
                );

                $rowHasData = true;
            }

            if (! $rowHasData) {
                $skipped++;
            }
        }

        return new ParsedLabImport(
            records: $records,
            warnings: $warnings,
            source: $this->getSource(),
            totalRows: count($dataRows),
            skippedRows: $skipped,
        );
    }

    public function getSource(): string
    {
        return 'ets_labs';
    }

    /**
     * Find the header row index — ETS sometimes has a title row before the actual headers.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function findHeaderRow(array $rows): ?int
    {
        // Check first 5 rows for the one that looks like column headers
        $searchLimit = min(5, count($rows));

        for ($i = 0; $i < $searchLimit; $i++) {
            if (! isset($rows[$i])) {
                continue;
            }

            $normalized = array_map(
                fn (string $h): string => strtolower(trim($h)),
                $rows[$i],
            );

            // A header row should have at least one ETS-identifying header and one test column
            $hasEts = false;
            $hasTest = false;

            foreach ($normalized as $header) {
                if (in_array($header, self::ETS_IDENTIFYING_HEADERS, true)) {
                    $hasEts = true;
                }
                if (isset(self::COLUMN_MAP[$header])) {
                    $hasTest = true;
                }
            }

            if ($hasEts && $hasTest) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the index of the first matching column from a set of possible header names.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $candidates
     */
    private function findColumnIndex(array $headers, array $candidates): ?int
    {
        foreach ($headers as $index => $header) {
            if (in_array($header, $candidates, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Map test column positions to their LabAnalysis test types.
     *
     * @param  array<int, string>  $headers
     * @return array<int, string> Column index → test_type
     */
    private function mapTestColumns(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            if (isset(self::COLUMN_MAP[$header])) {
                $testType = self::COLUMN_MAP[$header];
                // Avoid duplicate test type mappings (take the first match)
                if (! in_array($testType, $map, true)) {
                    $map[$index] = $testType;
                }
            }
        }

        return $map;
    }

    /**
     * Parse various date formats from lab CSVs.
     */
    private function parseDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        // Try common formats
        $formats = ['Y-m-d', 'm/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y', 'Y/m/d', 'd-M-Y', 'M d, Y'];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        // Try PHP's best guess
        try {
            $date = new \DateTimeImmutable($raw);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
