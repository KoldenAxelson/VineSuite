<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\FermentationRound;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createFermTestTenant(string $slug = 'ferm-winery', string $role = 'winemaker'): array
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
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

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
 * Helper: create a lot inside the current tenant.
 */
function createFermLot(array $overrides = []): Lot
{
    return Lot::create(array_merge([
        'name' => 'Fermentation Test Lot',
        'variety' => 'Cabernet Sauvignon',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
        'status' => 'in_progress',
    ], $overrides));
}

// ─── Tier 1: Event Logging ──────────────────────────────────────

it('writes fermentation_round_created event when creating a round', function () {
    [$tenant, $token] = createFermTestTenant('ferm-event-round');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'primary',
        'inoculation_date' => '2024-09-15',
        'yeast_strain' => 'EC-1118',
        'target_temp' => 80.0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $tenant->run(function () {
        $event = Event::where('operation_type', 'fermentation_round_created')->first();
        expect($event)->not->toBeNull();
        expect($event->entity_type)->toBe('lot');
        expect($event->payload['fermentation_type'])->toBe('primary');
        expect($event->payload['lot_name'])->toBe('Fermentation Test Lot');
        expect($event->payload['lot_variety'])->toBe('Cabernet Sauvignon');
        expect($event->payload['yeast_strain'])->toBe('EC-1118');
        expect($event->payload['inoculation_date'])->toBe('2024-09-15');
    });
});

it('writes fermentation_data_entered event when adding an entry', function () {
    [$tenant, $token] = createFermTestTenant('ferm-event-entry');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createFermLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'yeast_strain' => 'EC-1118',
            'target_temp' => 80.0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    $response = test()->postJson("/api/v1/fermentations/{$roundId}/entries", [
        'entry_date' => '2024-09-16',
        'temperature' => 78.5,
        'brix_or_density' => 24.5,
        'measurement_type' => 'brix',
        'notes' => 'Day 1 — vigorous fermentation',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $tenant->run(function () {
        $event = Event::where('operation_type', 'fermentation_data_entered')->first();
        expect($event)->not->toBeNull();
        expect($event->entity_type)->toBe('lot');
        expect($event->payload['lot_name'])->toBe('Fermentation Test Lot');
        expect($event->payload['entry_date'])->toBe('2024-09-16');
        expect((float) $event->payload['temperature'])->toBe(78.5);
        expect((float) $event->payload['brix_or_density'])->toBe(24.5);
        expect($event->payload['measurement_type'])->toBe('brix');
    });
});

it('writes fermentation_completed event when completing a round', function () {
    [$tenant, $token] = createFermTestTenant('ferm-event-complete');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createFermLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    $response = test()->postJson("/api/v1/fermentations/{$roundId}/complete", [
        'completion_date' => '2024-10-01',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $tenant->run(function () use ($roundId) {
        $event = Event::where('operation_type', 'fermentation_completed')->first();
        expect($event)->not->toBeNull();
        expect($event->payload['round_id'])->toBe($roundId);
        expect($event->payload['completion_date'])->toBe('2024-10-01');

        // Verify status updated
        $round = FermentationRound::find($roundId);
        expect($round->status)->toBe('completed');
        expect($round->completion_date->toDateString())->toBe('2024-10-01');
    });
});

// ─── Tier 1: Full Fermentation Lifecycle ─────────────────────────

it('supports full lifecycle: create round → daily entries → complete', function () {
    [$tenant, $token] = createFermTestTenant('ferm-lifecycle');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot(['name' => 'Lifecycle Lot'])->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // 1. Create primary fermentation round
    $roundResponse = test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'primary',
        'inoculation_date' => '2024-09-15',
        'yeast_strain' => 'D-254',
        'target_temp' => 82.0,
    ], $headers);

    $roundResponse->assertStatus(201);
    $roundId = $roundResponse->json('data.id');

    // 2. Add daily entries (Brix decreasing over time)
    $entries = [
        ['date' => '2024-09-16', 'temp' => 80.0, 'brix' => 24.5],
        ['date' => '2024-09-17', 'temp' => 82.0, 'brix' => 22.0],
        ['date' => '2024-09-18', 'temp' => 83.0, 'brix' => 18.5],
        ['date' => '2024-09-19', 'temp' => 81.0, 'brix' => 14.0],
        ['date' => '2024-09-20', 'temp' => 79.0, 'brix' => 8.5],
        ['date' => '2024-09-21', 'temp' => 77.0, 'brix' => 3.0],
        ['date' => '2024-09-22', 'temp' => 75.0, 'brix' => -1.0],
    ];

    foreach ($entries as $entry) {
        test()->postJson("/api/v1/fermentations/{$roundId}/entries", [
            'entry_date' => $entry['date'],
            'temperature' => $entry['temp'],
            'brix_or_density' => $entry['brix'],
            'measurement_type' => 'brix',
        ], $headers)->assertStatus(201);
    }

    // 3. Verify entries exist
    $entriesResponse = test()->getJson("/api/v1/fermentations/{$roundId}/entries", $headers);
    $entriesResponse->assertOk();
    expect($entriesResponse->json('data'))->toHaveCount(7);

    // 4. Complete the round
    test()->postJson("/api/v1/fermentations/{$roundId}/complete", [
        'completion_date' => '2024-09-22',
    ], $headers)->assertOk();

    // 5. Verify final state
    $tenant->run(function () use ($roundId) {
        $round = FermentationRound::find($roundId);
        expect($round->status)->toBe('completed');
        expect($round->entries()->count())->toBe(7);

        // Verify events
        $events = Event::where('operation_type', 'fermentation_data_entered')->count();
        expect($events)->toBe(7);
        expect(Event::where('operation_type', 'fermentation_completed')->count())->toBe(1);
    });
});

// ─── Tier 1: ML Fermentation Specifics ──────────────────────────

it('tracks ML fermentation with bacteria strain and confirmation date', function () {
    [$tenant, $token] = createFermTestTenant('ferm-ml');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot(['name' => 'ML Lot'])->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $response = test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 2,
        'fermentation_type' => 'malolactic',
        'inoculation_date' => '2024-10-01',
        'ml_bacteria' => 'VP41',
        'target_temp' => 68.0,
    ], $headers);

    $response->assertStatus(201);
    $data = $response->json('data');
    expect($data['fermentation_type'])->toBe('malolactic');
    expect($data['ml_bacteria'])->toBe('VP41');
    expect($data['yeast_strain'])->toBeNull();
});

// ─── Tier 1: Brix vs Specific Gravity ───────────────────────────

it('stores measurement_type alongside brix_or_density value', function () {
    [$tenant, $token] = createFermTestTenant('ferm-brix-sg');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createFermLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Brix entry
    $brixResponse = test()->postJson("/api/v1/fermentations/{$roundId}/entries", [
        'entry_date' => '2024-09-16',
        'temperature' => 80.0,
        'brix_or_density' => 24.5,
        'measurement_type' => 'brix',
    ], $headers);

    $brixResponse->assertStatus(201);
    expect($brixResponse->json('data.measurement_type'))->toBe('brix');

    // Reset auth guard for same-user follow-up
    app('auth')->forgetGuards();

    // Specific gravity entry
    $sgResponse = test()->postJson("/api/v1/fermentations/{$roundId}/entries", [
        'entry_date' => '2024-09-17',
        'temperature' => 82.0,
        'brix_or_density' => 1.065,
        'measurement_type' => 'specific_gravity',
    ], $headers);

    $sgResponse->assertStatus(201);
    expect($sgResponse->json('data.measurement_type'))->toBe('specific_gravity');
});

// ─── Tier 1: Tenant Isolation ───────────────────────────────────

it('prevents cross-tenant fermentation data access', function () {
    $tenantA = Tenant::create(['name' => 'Winery Alpha', 'slug' => 'ferm-iso-a', 'plan' => 'pro']);
    $tenantB = Tenant::create(['name' => 'Winery Beta', 'slug' => 'ferm-iso-b', 'plan' => 'pro']);

    $tenantA->run(function () {
        $lot = createFermLot();
        $user = User::create([
            'name' => 'Alpha User', 'email' => 'a@example.com',
            'password' => 'SecurePass123!', 'role' => 'winemaker', 'is_active' => true,
        ]);
        FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    });

    $tenantB->run(function () {
        expect(FermentationRound::count())->toBe(0);
        expect(Lot::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(FermentationRound::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD API ───────────────────────────────────────────

it('lists fermentation rounds for a lot', function () {
    [$tenant, $token] = createFermTestTenant('ferm-list');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = createFermLot();
        $lotId = $lot->id;
        $user = User::first();
        FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 1, 'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15', 'status' => 'active', 'created_by' => $user->id,
        ]);
        FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 2, 'fermentation_type' => 'malolactic',
            'inoculation_date' => '2024-10-01', 'status' => 'active', 'created_by' => $user->id,
        ]);
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/fermentations", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('filters fermentation rounds by type', function () {
    [$tenant, $token] = createFermTestTenant('ferm-filter');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = createFermLot();
        $lotId = $lot->id;
        $user = User::first();
        FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 1, 'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15', 'status' => 'active', 'created_by' => $user->id,
        ]);
        FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 2, 'fermentation_type' => 'malolactic',
            'inoculation_date' => '2024-10-01', 'status' => 'active', 'created_by' => $user->id,
        ]);
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/fermentations?fermentation_type=malolactic", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.fermentation_type'))->toBe('malolactic');
});

it('marks a round as stuck', function () {
    [$tenant, $token] = createFermTestTenant('ferm-stuck');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createFermLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 1, 'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15', 'status' => 'active', 'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    $response = test()->postJson("/api/v1/fermentations/{$roundId}/stuck", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('stuck');
});

// ─── Tier 2: Validation ─────────────────────────────────────────

it('rejects fermentation round with invalid type', function () {
    [$tenant, $token] = createFermTestTenant('ferm-val-type');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'spontaneous',
        'inoculation_date' => '2024-09-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects entry with invalid measurement_type', function () {
    [$tenant, $token] = createFermTestTenant('ferm-val-mtype');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createFermLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 1, 'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15', 'status' => 'active', 'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    $response = test()->postJson("/api/v1/fermentations/{$roundId}/entries", [
        'entry_date' => '2024-09-16',
        'temperature' => 80.0,
        'brix_or_density' => 24.5,
        'measurement_type' => 'plato',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

// ─── Tier 2: RBAC ───────────────────────────────────────────────

it('winemaker can create fermentation rounds', function () {
    [$tenant, $token] = createFermTestTenant('ferm-rbac-wm');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot()->id;
    });

    test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'primary',
        'inoculation_date' => '2024-09-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('cellar_hand cannot create fermentation rounds', function () {
    [$tenant, $token] = createFermTestTenant('ferm-rbac-ch', 'cellar_hand');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot()->id;
    });

    test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'primary',
        'inoculation_date' => '2024-09-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('cellar_hand can add fermentation entries', function () {
    [$tenant, $token] = createFermTestTenant('ferm-rbac-ch-entry', 'cellar_hand');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createFermLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 1, 'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15', 'status' => 'active', 'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    test()->postJson("/api/v1/fermentations/{$roundId}/entries", [
        'entry_date' => '2024-09-16',
        'temperature' => 80.0,
        'brix_or_density' => 24.5,
        'measurement_type' => 'brix',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('read_only cannot create fermentation rounds', function () {
    [$tenant, $token] = createFermTestTenant('ferm-rbac-ro', 'read_only');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot()->id;
    });

    test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'primary',
        'inoculation_date' => '2024-09-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read_only can list fermentation rounds', function () {
    [$tenant, $token] = createFermTestTenant('ferm-rbac-ro-list', 'read_only');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = createFermLot();
        $lotId = $lot->id;
        $user = User::first();
        FermentationRound::create([
            'lot_id' => $lot->id, 'round_number' => 1, 'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15', 'status' => 'active', 'created_by' => $user->id,
        ]);
    });

    test()->getJson("/api/v1/lots/{$lotId}/fermentations", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

// ─── Tier 2: API Envelope ───────────────────────────────────────

it('returns fermentation responses in the standard API envelope format', function () {
    [$tenant, $token] = createFermTestTenant('ferm-env');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createFermLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/fermentations", [
        'round_number' => 1,
        'fermentation_type' => 'primary',
        'inoculation_date' => '2024-09-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'round_number', 'fermentation_type', 'status'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});
