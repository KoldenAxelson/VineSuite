<?php

declare(strict_types=1);

namespace App\Services\LabImport;

use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Services\EventLogger;
use App\Services\LabThresholdChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the lab CSV import workflow: parse → preview → commit.
 *
 * Two-phase import:
 * 1. Preview: Upload CSV → parse → match lots → return preview for user review
 * 2. Commit: User confirms preview (with lot overrides) → create LabAnalysis records
 *
 * Each committed record writes a `lab_analysis_entered` event via the same
 * EventLogger path as manual entries, ensuring a consistent audit trail.
 */
class LabImportService
{
    /**
     * Registered parsers, tried in order (most specific first).
     *
     * @var array<int, LabCsvParser>
     */
    private array $parsers;

    public function __construct(
        protected EventLogger $eventLogger,
        protected LabThresholdChecker $thresholdChecker,
    ) {
        $this->parsers = [
            new ETSLabsParser,
            new GenericCSVParser,
        ];
    }

    /**
     * Phase 1: Parse a CSV file and return a preview with lot match suggestions.
     *
     * @param  string  $csvContent  Raw CSV file content
     * @return array{records: array<int, array<string, mixed>>, warnings: array<int, string>, source: string, total_rows: int, skipped_rows: int, parser: string}
     */
    public function preview(string $csvContent): array
    {
        $rows = $this->parseCsvContent($csvContent);

        if (empty($rows)) {
            return [
                'records' => [],
                'warnings' => ['CSV file is empty or could not be parsed.'],
                'source' => 'csv_import',
                'total_rows' => 0,
                'skipped_rows' => 0,
                'parser' => 'none',
            ];
        }

        // Find the right parser
        $parser = $this->detectParser($rows);

        if ($parser === null) {
            return [
                'records' => [],
                'warnings' => ['Could not detect CSV format. Ensure the file has a header row with recognizable test type columns (pH, TA, VA, etc.).'],
                'source' => 'csv_import',
                'total_rows' => count($rows) - 1,
                'skipped_rows' => count($rows) - 1,
                'parser' => 'none',
            ];
        }

        $result = $parser->parse($rows);

        // Match lots by name
        $this->matchLots($result->records);

        return [
            'records' => array_map(fn (ParsedLabRecord $r) => $r->toArray(), $result->records),
            'warnings' => $result->warnings,
            'source' => $result->source,
            'total_rows' => $result->totalRows,
            'skipped_rows' => $result->skippedRows,
            'parser' => $parser->getSource(),
        ];
    }

    /**
     * Phase 2: Commit confirmed records to the database.
     *
     * @param  array<int, array{lot_id: string, test_date: string, test_type: string, value: float, unit: string, method?: string|null, analyst?: string|null, notes?: string|null}>  $records
     * @param  string  $source  Source identifier (ets_labs, csv_import, etc.)
     * @param  string  $performedBy  UUID of the importing user
     * @return array{imported: int, alerts: int, errors: array<int, string>}
     */
    public function commit(array $records, string $source, string $performedBy): array
    {
        $imported = 0;
        $alertCount = 0;
        $errors = [];

        DB::transaction(function () use ($records, $source, $performedBy, &$imported, &$alertCount, &$errors) {
            foreach ($records as $index => $record) {
                try {
                    // Validate lot exists
                    $lot = Lot::find($record['lot_id']);
                    if (! $lot) {
                        $errors[] = "Record {$index}: Lot not found (ID: {$record['lot_id']}).";

                        continue;
                    }

                    // Validate test_type
                    if (! in_array($record['test_type'], LabAnalysis::TEST_TYPES, true)) {
                        $errors[] = "Record {$index}: Invalid test type '{$record['test_type']}'.";

                        continue;
                    }

                    $analysis = LabAnalysis::create([
                        'lot_id' => $record['lot_id'],
                        'test_date' => $record['test_date'],
                        'test_type' => $record['test_type'],
                        'value' => $record['value'],
                        'unit' => $record['unit'],
                        'method' => $record['method'] ?? null,
                        'analyst' => $record['analyst'] ?? null,
                        'notes' => $record['notes'] ?? null,
                        'source' => $source,
                        'performed_by' => $performedBy,
                    ]);

                    $analysis->load('lot');

                    // Write event with self-contained payload
                    $this->eventLogger->log(
                        entityType: 'lot',
                        entityId: $analysis->lot_id,
                        operationType: 'lab_analysis_entered',
                        payload: [
                            'analysis_id' => $analysis->id,
                            'lot_name' => $analysis->lot->name,
                            'lot_variety' => $analysis->lot->variety,
                            'test_type' => $analysis->test_type,
                            'value' => (float) $analysis->value,
                            'unit' => $analysis->unit,
                            'method' => $analysis->method,
                            'analyst' => $analysis->analyst,
                            'source' => $source,
                            'test_date' => $analysis->test_date->toDateString(),
                            'import_batch' => true,
                        ],
                        performedBy: $performedBy,
                        performedAt: $analysis->test_date,
                    );

                    // Check thresholds
                    $alerts = $this->thresholdChecker->check($analysis);
                    $alertCount += count($alerts);

                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Record {$index}: {$e->getMessage()}";
                }
            }
        });

        Log::info('Lab CSV import committed', [
            'imported' => $imported,
            'errors' => count($errors),
            'alerts_triggered' => $alertCount,
            'source' => $source,
            'tenant_id' => tenant('id'),
            'user_id' => $performedBy,
        ]);

        return [
            'imported' => $imported,
            'alerts' => $alertCount,
            'errors' => $errors,
        ];
    }

    /**
     * Match parsed records to existing lots by name (exact + fuzzy).
     *
     * @param  array<int, ParsedLabRecord>  $records
     */
    private function matchLots(array $records): void
    {
        // Group unique lot names from the CSV
        $lotNames = [];
        foreach ($records as $record) {
            if ($record->lotName !== null && ! isset($lotNames[$record->lotName])) {
                $lotNames[$record->lotName] = true;
            }
        }

        // Cache lot lookups
        /** @var array<string, array{id: string|null, suggestions: array<int, array{id: string, name: string, variety: string, vintage: int}>}> */
        $lotCache = [];

        foreach (array_keys($lotNames) as $lotName) {
            $lotCache[$lotName] = $this->findLot($lotName);
        }

        // Apply matches to records
        foreach ($records as $record) {
            if ($record->lotName === null) {
                continue;
            }

            $match = $lotCache[$record->lotName] ?? null;
            if ($match !== null) {
                $record->lotId = $match['id'];
                $record->lotSuggestions = $match['suggestions'];
            }
        }
    }

    /**
     * Find a lot by name — exact match first, then fuzzy suggestions.
     *
     * @return array{id: string|null, suggestions: array<int, array{id: string, name: string, variety: string, vintage: int}>}
     */
    private function findLot(string $name): array
    {
        // Try exact match (case-insensitive)
        $exact = Lot::where('name', 'ilike', $name)->first();

        if ($exact) {
            return [
                'id' => $exact->id,
                'suggestions' => [[
                    'id' => $exact->id,
                    'name' => $exact->name,
                    'variety' => $exact->variety,
                    'vintage' => $exact->vintage,
                ]],
            ];
        }

        // Fuzzy search — split into words so "Cabernet 2024" matches "Cabernet Sauvignon Estate 2024"
        $query = Lot::query();
        $words = preg_split('/\s+/', trim($name));
        foreach ($words as $word) {
            $query->where('name', 'ilike', "%{$word}%");
        }

        $suggestions = $query->limit(5)
            ->get()
            ->map(fn (Lot $lot) => [
                'id' => $lot->id,
                'name' => $lot->name,
                'variety' => $lot->variety,
                'vintage' => $lot->vintage,
            ])
            ->all();

        return [
            'id' => null,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Parse raw CSV content into rows.
     *
     * @return array<int, array<int, string>>
     */
    private function parseCsvContent(string $csvContent): array
    {
        $rows = [];

        // Handle different line endings
        $csvContent = str_replace(["\r\n", "\r"], "\n", $csvContent);
        $lines = explode("\n", $csvContent);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = array_map('strval', $row);
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Detect which parser can handle this CSV format.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function detectParser(array $rows): ?LabCsvParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->canParse($rows)) {
                return $parser;
            }
        }

        return null;
    }
}
