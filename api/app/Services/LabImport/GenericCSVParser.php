<?php

declare(strict_types=1);

namespace App\Services\LabImport;

use App\Models\LabAnalysis;

/**
 * Generic CSV parser for lab analysis imports.
 *
 * Handles CSV files that don't match a specific lab format (ETS, OenoFoss, etc.).
 * Expects a header row with recognizable column names for test types.
 *
 * This parser is the fallback — it accepts any CSV with at least one
 * recognizable test type column. Column matching is case-insensitive
 * and tolerates common variations (spaces, underscores, abbreviations).
 */
class GenericCSVParser implements LabCsvParser
{
    /**
     * Column header → test_type mapping.
     *
     * Covers common naming variations across various lab systems.
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        // pH
        'ph' => 'pH',
        // Titratable Acidity
        'ta' => 'TA',
        'titratable acidity' => 'TA',
        'titratable_acidity' => 'TA',
        'total acidity' => 'TA',
        // Volatile Acidity
        'va' => 'VA',
        'volatile acidity' => 'VA',
        'volatile_acidity' => 'VA',
        // SO2
        'free so2' => 'free_SO2',
        'free_so2' => 'free_SO2',
        'fso2' => 'free_SO2',
        'total so2' => 'total_SO2',
        'total_so2' => 'total_SO2',
        'tso2' => 'total_SO2',
        // Sugar
        'residual sugar' => 'residual_sugar',
        'residual_sugar' => 'residual_sugar',
        'rs' => 'residual_sugar',
        // Alcohol
        'alcohol' => 'alcohol',
        'abv' => 'alcohol',
        'ethanol' => 'alcohol',
        // Malic acid
        'malic acid' => 'malic_acid',
        'malic_acid' => 'malic_acid',
        // Glucose/Fructose
        'glucose_fructose' => 'glucose_fructose',
        'glucose + fructose' => 'glucose_fructose',
        'glucose/fructose' => 'glucose_fructose',
        // Turbidity
        'turbidity' => 'turbidity',
        'ntu' => 'turbidity',
        // Color
        'color' => 'color',
        'color intensity' => 'color',
        'colour' => 'color',
    ];

    /**
     * Possible lot/sample name headers.
     *
     * @var array<int, string>
     */
    private const LOT_HEADERS = [
        'lot', 'lot name', 'lot_name', 'wine', 'wine name', 'wine_name',
        'sample', 'sample name', 'sample_name', 'sample id', 'sample_id',
        'description', 'id', 'name',
    ];

    /**
     * Possible date headers.
     *
     * @var array<int, string>
     */
    private const DATE_HEADERS = [
        'date', 'test date', 'test_date', 'analysis date', 'analysis_date',
        'date received', 'date_received', 'report date', 'report_date',
        'sample date', 'sample_date',
    ];

    public function canParse(array $rows): bool
    {
        if (count($rows) < 2) {
            return false;
        }

        // Must have at least one recognizable test column in row 0
        $headers = array_map(
            fn (string $h): string => strtolower(trim($h)),
            $rows[0],
        );

        foreach ($headers as $header) {
            if (isset(self::COLUMN_MAP[$header])) {
                return true;
            }
        }

        return false;
    }

    public function parse(array $rows): ParsedLabImport
    {
        if (count($rows) < 2) {
            return new ParsedLabImport(
                records: [],
                warnings: ['CSV file is empty or has only headers.'],
                source: $this->getSource(),
                totalRows: 0,
                skippedRows: 0,
            );
        }

        $headers = array_map(
            fn (string $h): string => strtolower(trim($h)),
            $rows[0],
        );

        $lotColIndex = $this->findColumnIndex($headers, self::LOT_HEADERS);
        $dateColIndex = $this->findColumnIndex($headers, self::DATE_HEADERS);
        $testColumns = $this->mapTestColumns($headers);

        $records = [];
        $warnings = [];
        $skipped = 0;
        $dataRows = array_slice($rows, 1, preserve_keys: true);

        if (empty($testColumns)) {
            return new ParsedLabImport(
                records: [],
                warnings: ['No recognizable test type columns found in CSV headers.'],
                source: $this->getSource(),
                totalRows: count($dataRows),
                skippedRows: count($dataRows),
            );
        }

        foreach ($dataRows as $rowIndex => $row) {
            $csvRowNumber = $rowIndex + 1;

            // Skip empty rows
            $joined = implode('', array_map('trim', $row));
            if ($joined === '') {
                $skipped++;

                continue;
            }

            $lotName = $lotColIndex !== null ? trim($row[$lotColIndex] ?? '') : null;
            if ($lotName === '') {
                $lotName = null;
            }

            $testDate = $this->parseDate($dateColIndex !== null ? trim($row[$dateColIndex] ?? '') : '');
            if ($testDate === null) {
                $warnings[] = "Row {$csvRowNumber}: No date found, using today's date.";
                $testDate = date('Y-m-d');
            }

            $rowHasData = false;
            foreach ($testColumns as $colIndex => $testType) {
                $rawValue = trim($row[$colIndex] ?? '');

                if ($rawValue === '' || strtolower($rawValue) === 'n/a' || strtolower($rawValue) === '-') {
                    continue;
                }

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
        return 'csv_import';
    }

    /**
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
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    private function mapTestColumns(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            if (isset(self::COLUMN_MAP[$header])) {
                $testType = self::COLUMN_MAP[$header];
                if (! in_array($testType, $map, true)) {
                    $map[$index] = $testType;
                }
            }
        }

        return $map;
    }

    private function parseDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $formats = ['Y-m-d', 'm/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y', 'Y/m/d', 'd-M-Y', 'M d, Y'];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        try {
            $date = new \DateTimeImmutable($raw);

            return $date->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
