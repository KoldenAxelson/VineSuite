<?php

declare(strict_types=1);

namespace App\Services\LabImport;

/**
 * Value object representing the result of parsing a lab CSV file.
 *
 * Contains normalized records ready for preview/commit and any
 * warnings generated during parsing (skipped rows, unrecognized columns, etc.).
 */
class ParsedLabImport
{
    /**
     * @param  array<int, ParsedLabRecord>  $records  Normalized lab analysis records
     * @param  array<int, string>  $warnings  Human-readable warnings from parsing
     * @param  string  $source  Source identifier (ets_labs, oenofoss, wine_scan, csv_import)
     * @param  int  $totalRows  Total rows in the original CSV (excluding headers)
     * @param  int  $skippedRows  Rows skipped due to parsing issues
     */
    public function __construct(
        public readonly array $records,
        public readonly array $warnings,
        public readonly string $source,
        public readonly int $totalRows,
        public readonly int $skippedRows,
    ) {}
}
