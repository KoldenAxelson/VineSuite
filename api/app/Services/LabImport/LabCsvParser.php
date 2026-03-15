<?php

declare(strict_types=1);

namespace App\Services\LabImport;

/**
 * Contract for lab CSV parsers.
 *
 * Each parser handles a specific external lab's CSV export format.
 * Parsers are resilient to column reordering and skip empty rows.
 */
interface LabCsvParser
{
    /**
     * Detect whether this parser can handle the given CSV content.
     *
     * @param  array<int, array<int, string>>  $rows  First N rows of the CSV
     * @return bool True if this parser recognizes the format
     */
    public function canParse(array $rows): bool;

    /**
     * Parse the CSV rows into normalized lab analysis records.
     *
     * @param  array<int, array<int, string>>  $rows  All CSV rows (including headers)
     * @return ParsedLabImport The parsed result with records and any warnings
     */
    public function parse(array $rows): ParsedLabImport;

    /**
     * Return the source identifier for records imported by this parser.
     */
    public function getSource(): string;
}
