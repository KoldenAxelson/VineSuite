<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\LabAnalysis;
use App\Models\LabThreshold;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LabImport\ETSLabsParser;
use App\Services\LabImport\GenericCSVParser;
use App\Services\LabImport\LabImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createImportTestTenant(string $slug = 'import-winery', string $role = 'winemaker'): array
{
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $tenant->run(function () use ($role) {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test '.ucfirst($role),
            'email' => "{$role}@example.com",
            'password' => 'SecurePass123!',
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => "{$role}@example.com",
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('data.token')];
}

afterEach(function () {
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $schemas = DB::select(
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"
    );
    foreach ($schemas as $schema) {
        DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
    }
});

/*
 * Helper: build an ETS Labs-style CSV string.
 */
function buildEtsCsv(array $rows, array $headers = ['Wine', 'Date Received', 'pH', 'TA', 'VA', 'Free SO2']): string
{
    $csv = implode(',', $headers)."\n";
    foreach ($rows as $row) {
        $csv .= implode(',', $row)."\n";
    }

    return $csv;
}

/*
 * Helper: build a generic CSV string.
 */
function buildGenericCsv(array $rows, array $headers = ['Lot Name', 'Date', 'pH', 'TA', 'VA']): string
{
    $csv = implode(',', $headers)."\n";
    foreach ($rows as $row) {
        $csv .= implode(',', $row)."\n";
    }

    return $csv;
}

// ─── Tier 1: ETS Labs Parser Unit Tests ──────────────────────────

it('parses a standard ETS Labs CSV with multiple test types', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['Wine', 'Date Received', 'pH', 'TA', 'VA', 'Free SO2', 'Total SO2'],
        ['Cab Sauv 2024', '10/15/2024', '3.45', '6.2', '0.04', '28', '85'],
        ['Merlot 2024', '10/15/2024', '3.52', '5.8', '0.05', '32', '92'],
    ];

    expect($parser->canParse($csv))->toBeTrue();

    $result = $parser->parse($csv);
    expect($result->records)->toHaveCount(10); // 2 rows × 5 test types
    expect($result->source)->toBe('ets_labs');
    expect($result->skippedRows)->toBe(0);

    // Check first record (pH for Cab Sauv)
    $phRecord = $result->records[0];
    expect($phRecord->lotName)->toBe('Cab Sauv 2024');
    expect($phRecord->testType)->toBe('pH');
    expect($phRecord->value)->toBe(3.45);
    expect($phRecord->testDate)->toBe('2024-10-15');
    expect($phRecord->analyst)->toBe('ETS Laboratories');
});

it('handles ETS CSV with extra title row before headers', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['ETS Laboratories — Wine Analysis Report'],
        ['Wine', 'Date Received', 'pH', 'TA', 'VA'],
        ['Pinot Noir 2024', '10/20/2024', '3.38', '7.1', '0.03'],
    ];

    expect($parser->canParse($csv))->toBeTrue();

    $result = $parser->parse($csv);
    expect($result->records)->toHaveCount(3);
    expect($result->records[0]->lotName)->toBe('Pinot Noir 2024');
});

it('skips empty rows and N/A values in ETS CSV', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['Wine', 'Date Received', 'pH', 'TA', 'VA'],
        ['Cab 2024', '10/15/2024', '3.45', 'N/A', '0.04'],
        ['', '', '', '', ''],
        ['Merlot 2024', '10/15/2024', '3.52', '5.8', '-'],
    ];

    $result = $parser->parse($csv);
    // Row 1: pH + VA (TA is N/A) = 2
    // Row 2: empty, skipped
    // Row 3: pH + TA (VA is -) = 2
    expect($result->records)->toHaveCount(4);
    expect($result->skippedRows)->toBe(1);
});

it('handles reordered columns in ETS CSV', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['VA', 'Date Received', 'Wine', 'Free SO2', 'pH'],
        ['0.04', '10/15/2024', 'Syrah 2024', '28', '3.55'],
    ];

    expect($parser->canParse($csv))->toBeTrue();

    $result = $parser->parse($csv);
    expect($result->records)->toHaveCount(3);

    // Find the pH record
    $phRecords = array_filter($result->records, fn ($r) => $r->testType === 'pH');
    $phRecord = reset($phRecords);
    expect($phRecord->value)->toBe(3.55);
    expect($phRecord->lotName)->toBe('Syrah 2024');
});

it('handles non-numeric values with warnings', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['Wine', 'Date Received', 'pH', 'TA'],
        ['Test Lot', '10/15/2024', 'pending', '6.2'],
    ];

    $result = $parser->parse($csv);
    expect($result->records)->toHaveCount(1); // Only TA is valid
    expect($result->warnings)->toHaveCount(1);
    expect($result->warnings[0])->toContain('Non-numeric');
});

it('does not match non-lab CSV formats', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['Name', 'Email', 'Phone'],
        ['John Doe', 'john@example.com', '555-1234'],
    ];

    expect($parser->canParse($csv))->toBeFalse();
});

// ─── Tier 1: Generic CSV Parser Unit Tests ──────────────────────

it('parses a generic CSV with standard column names', function () {
    $parser = new GenericCSVParser;

    $csv = [
        ['Lot', 'Date', 'pH', 'TA', 'VA'],
        ['Test Lot A', '2024-10-15', '3.45', '6.2', '0.04'],
    ];

    expect($parser->canParse($csv))->toBeTrue();

    $result = $parser->parse($csv);
    expect($result->records)->toHaveCount(3);
    expect($result->source)->toBe('csv_import');
});

it('handles underscore-style column names', function () {
    $parser = new GenericCSVParser;

    $csv = [
        ['lot_name', 'test_date', 'free_so2', 'total_so2', 'residual_sugar'],
        ['Chard 2024', '2024-10-15', '28', '85', '1.2'],
    ];

    expect($parser->canParse($csv))->toBeTrue();

    $result = $parser->parse($csv);
    expect($result->records)->toHaveCount(3);

    $types = array_map(fn ($r) => $r->testType, $result->records);
    expect($types)->toContain('free_SO2');
    expect($types)->toContain('total_SO2');
    expect($types)->toContain('residual_sugar');
});

it('returns empty result for CSV with no recognizable columns', function () {
    $parser = new GenericCSVParser;

    $csv = [
        ['foo', 'bar', 'baz'],
        ['1', '2', '3'],
    ];

    expect($parser->canParse($csv))->toBeFalse();
});

// ─── Tier 1: Import Service — Lot Matching ──────────────────────

it('matches lots by exact name during preview', function () {
    [$tenant] = createImportTestTenant('import-match');

    $tenant->run(function () {
        Lot::create([
            'name' => 'Cab Sauv 2024',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $service = app(LabImportService::class);
        $csv = buildEtsCsv([
            ['Cab Sauv 2024', '10/15/2024', '3.45', '6.2', '0.04', '28'],
        ]);

        $result = $service->preview($csv);

        // All records should have the lot matched
        foreach ($result['records'] as $record) {
            expect($record['lot_id'])->not->toBeNull();
            expect($record['lot_name'])->toBe('Cab Sauv 2024');
        }
    });
});

it('provides fuzzy lot suggestions when no exact match exists', function () {
    [$tenant] = createImportTestTenant('import-fuzzy');

    $tenant->run(function () {
        Lot::create([
            'name' => 'Cabernet Sauvignon Estate 2024',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $service = app(LabImportService::class);
        $csv = buildEtsCsv([
            ['Cabernet 2024', '10/15/2024', '3.45', '6.2', '0.04', '28'],
        ]);

        $result = $service->preview($csv);

        // No exact match, but should have suggestions
        $firstRecord = $result['records'][0];
        expect($firstRecord['lot_id'])->toBeNull();
        expect($firstRecord['lot_suggestions'])->not->toBeEmpty();
        expect($firstRecord['lot_suggestions'][0]['name'])->toContain('Cabernet');
    });
});

// ─── Tier 1: Import Service — Commit with Event Logging ─────────

it('writes lab_analysis_entered events for each imported record', function () {
    [$tenant] = createImportTestTenant('import-events');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'Event Test Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);

        $user = User::first();
        $service = app(LabImportService::class);

        $result = $service->commit(
            records: [
                [
                    'lot_id' => $lot->id,
                    'test_date' => '2024-10-15',
                    'test_type' => 'pH',
                    'value' => 3.45,
                    'unit' => 'pH',
                    'analyst' => 'ETS Laboratories',
                ],
                [
                    'lot_id' => $lot->id,
                    'test_date' => '2024-10-15',
                    'test_type' => 'VA',
                    'value' => 0.04,
                    'unit' => 'g/100mL',
                    'analyst' => 'ETS Laboratories',
                ],
            ],
            source: 'ets_labs',
            performedBy: $user->id,
        );

        expect($result['imported'])->toBe(2);
        expect($result['errors'])->toBeEmpty();

        // Verify events written
        $events = Event::where('operation_type', 'lab_analysis_entered')->get();
        expect($events)->toHaveCount(2);

        // Verify self-contained payload
        $phEvent = $events->firstWhere(fn ($e) => $e->payload['test_type'] === 'pH');
        expect($phEvent->payload['lot_name'])->toBe('Event Test Lot');
        expect($phEvent->payload['lot_variety'])->toBe('Merlot');
        expect($phEvent->payload['source'])->toBe('ets_labs');
        expect($phEvent->payload['import_batch'])->toBeTrue();
    });
});

it('triggers threshold alerts during import commit', function () {
    [$tenant] = createImportTestTenant('import-alerts');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'Alert Import Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);

        $user = User::first();
        $service = app(LabImportService::class);

        $result = $service->commit(
            records: [
                [
                    'lot_id' => $lot->id,
                    'test_date' => '2024-10-15',
                    'test_type' => 'VA',
                    'value' => 0.15,
                    'unit' => 'g/100mL',
                ],
            ],
            source: 'ets_labs',
            performedBy: $user->id,
        );

        expect($result['imported'])->toBe(1);
        expect($result['alerts'])->toBe(1);
    });
});

it('records source as the external lab name on imported analyses', function () {
    [$tenant] = createImportTestTenant('import-source');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'Source Test Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'in_progress',
        ]);

        $user = User::first();
        $service = app(LabImportService::class);

        $service->commit(
            records: [[
                'lot_id' => $lot->id,
                'test_date' => '2024-10-15',
                'test_type' => 'pH',
                'value' => 3.38,
                'unit' => 'pH',
            ]],
            source: 'ets_labs',
            performedBy: $user->id,
        );

        $analysis = LabAnalysis::first();
        expect($analysis->source)->toBe('ets_labs');
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant lot matching during import', function () {
    $tenantA = Tenant::create(['name' => 'Winery Alpha', 'slug' => 'import-iso-a', 'plan' => 'pro']);
    $tenantB = Tenant::create(['name' => 'Winery Beta', 'slug' => 'import-iso-b', 'plan' => 'pro']);

    $tenantA->run(function () {
        Lot::create([
            'name' => 'Secret Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
    });

    $tenantB->run(function () {
        $service = app(LabImportService::class);
        $csv = buildEtsCsv([
            ['Secret Lot', '10/15/2024', '3.45', '6.2', '0.04', '28'],
        ]);

        $result = $service->preview($csv);

        // Tenant B should NOT find Tenant A's lot
        foreach ($result['records'] as $record) {
            expect($record['lot_id'])->toBeNull();
            expect($record['lot_suggestions'])->toBeEmpty();
        }
    });
});

// ─── Tier 2: API Endpoint — Preview ─────────────────────────────

it('uploads and previews a CSV via the API', function () {
    [$tenant, $token] = createImportTestTenant('import-api-preview');

    $tenant->run(function () {
        Lot::create([
            'name' => 'API Lot',
            'variety' => 'Chardonnay',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);
    });

    $csvContent = buildEtsCsv([
        ['API Lot', '10/15/2024', '3.45', '6.2', '0.04', '28'],
    ]);

    $file = UploadedFile::fake()->createWithContent('lab_results.csv', $csvContent);

    $response = test()->postJson('/api/v1/lab-import/preview', [
        'file' => $file,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $data = $response->json('data');
    expect($data['records'])->not->toBeEmpty();
    expect($data['source'])->toBe('ets_labs');
    expect($data['records'][0]['lot_id'])->not->toBeNull();
});

it('uploads and previews a generic CSV via the API', function () {
    [$tenant, $token] = createImportTestTenant('import-api-generic');

    $csvContent = buildGenericCsv([
        ['Some Lot', '2024-10-15', '3.45', '6.2', '0.04'],
    ]);

    $file = UploadedFile::fake()->createWithContent('generic_lab.csv', $csvContent);

    $response = test()->postJson('/api/v1/lab-import/preview', [
        'file' => $file,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $data = $response->json('data');
    expect($data['records'])->not->toBeEmpty();
    expect($data['parser'])->toBe('csv_import');
});

// ─── Tier 2: API Endpoint — Commit ──────────────────────────────

it('commits previewed records via the API', function () {
    [$tenant, $token] = createImportTestTenant('import-api-commit');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Commit Lot',
            'variety' => 'Zinfandel',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 600,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson('/api/v1/lab-import/commit', [
        'records' => [
            [
                'lot_id' => $lotId,
                'test_date' => '2024-10-15',
                'test_type' => 'pH',
                'value' => 3.55,
                'unit' => 'pH',
                'analyst' => 'ETS Laboratories',
            ],
            [
                'lot_id' => $lotId,
                'test_date' => '2024-10-15',
                'test_type' => 'TA',
                'value' => 5.8,
                'unit' => 'g/L',
            ],
        ],
        'source' => 'ets_labs',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data.imported'))->toBe(2);
    expect($response->json('data.errors'))->toBeEmpty();

    // Verify records exist in database
    $tenant->run(function () {
        expect(LabAnalysis::count())->toBe(2);
        expect(LabAnalysis::where('source', 'ets_labs')->count())->toBe(2);
    });
});

// ─── Tier 2: Validation ─────────────────────────────────────────

it('rejects preview without a file', function () {
    [$tenant, $token] = createImportTestTenant('import-val-nofile');

    $response = test()->postJson('/api/v1/lab-import/preview', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects commit with invalid source', function () {
    [$tenant, $token] = createImportTestTenant('import-val-source');

    $response = test()->postJson('/api/v1/lab-import/commit', [
        'records' => [[
            'lot_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.45,
            'unit' => 'pH',
        ]],
        'source' => 'unknown_lab',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects commit with missing lot_id', function () {
    [$tenant, $token] = createImportTestTenant('import-val-lot');

    $response = test()->postJson('/api/v1/lab-import/commit', [
        'records' => [[
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.45,
            'unit' => 'pH',
        ]],
        'source' => 'csv_import',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

// ─── Tier 2: RBAC ───────────────────────────────────────────────

it('winemaker can import lab data', function () {
    [$tenant, $token] = createImportTestTenant('import-rbac-wm');

    $csvContent = buildEtsCsv([
        ['Some Lot', '10/15/2024', '3.45', '6.2', '0.04', '28'],
    ]);
    $file = UploadedFile::fake()->createWithContent('lab.csv', $csvContent);

    test()->postJson('/api/v1/lab-import/preview', [
        'file' => $file,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

it('cellar_hand cannot import lab data', function () {
    [$tenant, $token] = createImportTestTenant('import-rbac-ch', 'cellar_hand');

    $csvContent = buildEtsCsv([
        ['Some Lot', '10/15/2024', '3.45', '6.2', '0.04', '28'],
    ]);
    $file = UploadedFile::fake()->createWithContent('lab.csv', $csvContent);

    test()->postJson('/api/v1/lab-import/preview', [
        'file' => $file,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read_only cannot import lab data', function () {
    [$tenant, $token] = createImportTestTenant('import-rbac-ro', 'read_only');

    $csvContent = buildEtsCsv([
        ['Some Lot', '10/15/2024', '3.45', '6.2', '0.04', '28'],
    ]);
    $file = UploadedFile::fake()->createWithContent('lab.csv', $csvContent);

    test()->postJson('/api/v1/lab-import/preview', [
        'file' => $file,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

// ─── Tier 2: API Envelope ───────────────────────────────────────

it('returns import responses in the standard API envelope format', function () {
    [$tenant, $token] = createImportTestTenant('import-env');

    $csvContent = buildEtsCsv([
        ['Test Lot', '10/15/2024', '3.45', '6.2', '0.04', '28'],
    ]);
    $file = UploadedFile::fake()->createWithContent('lab.csv', $csvContent);

    $response = test()->postJson('/api/v1/lab-import/preview', [
        'file' => $file,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => ['records', 'warnings', 'source', 'total_rows', 'skipped_rows', 'parser'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

// ─── Tier 2: Edge Cases ─────────────────────────────────────────

it('handles CSV with only headers and no data rows', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['Wine', 'Date Received', 'pH', 'TA', 'VA'],
    ];

    // A headers-only file has no data — canParse returns false (needs at least 2 rows)
    expect($parser->canParse($csv))->toBeFalse();

    // GenericCSVParser also returns false for single-row files
    $generic = new GenericCSVParser;
    expect($generic->canParse($csv))->toBeFalse();
});

it('handles values with less-than/greater-than prefixes', function () {
    $parser = new ETSLabsParser;

    $csv = [
        ['Wine', 'Date Received', 'pH', 'Residual Sugar'],
        ['Dry Wine', '10/15/2024', '3.45', '<0.5'],
    ];

    $result = $parser->parse($csv);
    $rsRecords = array_filter($result->records, fn ($r) => $r->testType === 'residual_sugar');
    $rsRecord = reset($rsRecords);

    expect($rsRecord)->not->toBeFalse();
    expect($rsRecord->value)->toBe(0.5);
});

it('commit handles invalid lot_id gracefully with error', function () {
    [$tenant] = createImportTestTenant('import-bad-lot');

    $tenant->run(function () {
        $user = User::first();
        $service = app(LabImportService::class);

        $result = $service->commit(
            records: [[
                'lot_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'test_date' => '2024-10-15',
                'test_type' => 'pH',
                'value' => 3.45,
                'unit' => 'pH',
            ]],
            source: 'csv_import',
            performedBy: $user->id,
        );

        expect($result['imported'])->toBe(0);
        expect($result['errors'])->toHaveCount(1);
        expect($result['errors'][0])->toContain('Lot not found');
    });
});
