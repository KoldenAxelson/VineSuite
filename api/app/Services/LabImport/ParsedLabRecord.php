<?php

declare(strict_types=1);

namespace App\Services\LabImport;

/**
 * A single parsed lab analysis record from a CSV import.
 *
 * Holds the raw parsed data plus lot matching metadata.
 * The lot_id is resolved during preview (user confirms matches).
 */
class ParsedLabRecord
{
    /**
     * @param  int  $rowNumber  Original CSV row number (for error reporting)
     * @param  string|null  $lotName  Lot name/identifier from the CSV (for matching)
     * @param  string  $testDate  ISO date string
     * @param  string  $testType  Normalized test type (must be in LabAnalysis::TEST_TYPES)
     * @param  float  $value  Measured value
     * @param  string  $unit  Unit of measurement
     * @param  string|null  $method  Analytical method (if provided in CSV)
     * @param  string|null  $analyst  Analyst name (if provided in CSV)
     * @param  string|null  $notes  Notes from CSV
     * @param  string|null  $lotId  Resolved lot UUID (null until matched)
     * @param  array<int, array{id: string, name: string, variety: string, vintage: int}>  $lotSuggestions  Fuzzy match suggestions
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly ?string $lotName,
        public readonly string $testDate,
        public readonly string $testType,
        public readonly float $value,
        public readonly string $unit,
        public readonly ?string $method = null,
        public readonly ?string $analyst = null,
        public readonly ?string $notes = null,
        public ?string $lotId = null,
        public array $lotSuggestions = [],
    ) {}

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'lot_name' => $this->lotName,
            'lot_id' => $this->lotId,
            'lot_suggestions' => $this->lotSuggestions,
            'test_date' => $this->testDate,
            'test_type' => $this->testType,
            'value' => $this->value,
            'unit' => $this->unit,
            'method' => $this->method,
            'analyst' => $this->analyst,
            'notes' => $this->notes,
        ];
    }
}
