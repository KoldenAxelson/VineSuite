<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\SensoryNote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createSensoryTestTenant(string $slug = 'sensory-winery', string $role = 'winemaker'): array
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
 * Helper: create a lot inside the current tenant.
 */
function createSensoryLot(array $overrides = []): Lot
{
    return Lot::create(array_merge([
        'name' => 'Sensory Test Lot',
        'variety' => 'Chardonnay',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 400,
        'status' => 'in_progress',
    ], $overrides));
}

// ─── Tier 1: Event Logging ──────────────────────────────────────

it('writes sensory_note_recorded event with self-contained payload', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-event');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 4.2,
        'rating_scale' => 'five_point',
        'nose_notes' => 'Citrus, green apple, floral',
        'palate_notes' => 'Crisp acidity, clean finish',
        'overall_notes' => 'Developing well',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $tenant->run(function () {
        $event = Event::where('operation_type', 'sensory_note_recorded')->first();
        expect($event)->not->toBeNull();
        expect($event->entity_type)->toBe('lot');
        expect($event->payload['lot_name'])->toBe('Sensory Test Lot');
        expect($event->payload['lot_variety'])->toBe('Chardonnay');
        expect($event->payload['taster_name'])->toBe('Test Winemaker');
        expect($event->payload['date'])->toBe('2024-11-15');
        expect((float) $event->payload['rating'])->toBe(4.2);
        expect($event->payload['rating_scale'])->toBe('five_point');
        expect($event->payload['has_nose_notes'])->toBe(true);
        expect($event->payload['has_palate_notes'])->toBe(true);
        expect($event->payload['has_overall_notes'])->toBe(true);
    });
});

// ─── Tier 1: Rating Scales ──────────────────────────────────────

it('supports five-point rating scale', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-five-pt');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 3.8,
        'rating_scale' => 'five_point',
        'nose_notes' => 'Ripe fruit',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    expect((float) $response->json('data.rating'))->toBe(3.8);
    expect($response->json('data.rating_scale'))->toBe('five_point');
});

it('supports hundred-point rating scale', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-100pt');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 91,
        'rating_scale' => 'hundred_point',
        'palate_notes' => 'Outstanding balance',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    expect((float) $response->json('data.rating'))->toBe(91.0);
    expect($response->json('data.rating_scale'))->toBe('hundred_point');
});

it('allows notes without a rating', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-no-rating');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'nose_notes' => 'Still developing, needs time',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.rating'))->toBeNull();
});

// ─── Tier 1: Multiple Tasters Same Lot ──────────────────────────

it('allows multiple tasters to note the same lot on the same date', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-multi-taster');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;

        // Create second taster
        $taster2 = User::create([
            'name' => 'Second Taster',
            'email' => 'taster2@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $taster2->assignRole('winemaker');
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // First taster notes
    test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 4.0,
        'rating_scale' => 'five_point',
        'nose_notes' => 'Cherry and oak',
    ], $headers)->assertStatus(201);

    // Login as second taster
    app('auth')->forgetGuards();

    $login2 = test()->postJson('/api/v1/auth/login', [
        'email' => 'taster2@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token2 = $login2->json('data.token');

    // Second taster notes same lot, same date
    test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 3.5,
        'rating_scale' => 'five_point',
        'nose_notes' => 'Earthy, mushroom',
    ], [
        'Authorization' => "Bearer {$token2}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    // Both notes exist
    $tenant->run(function () use ($lotId) {
        expect(SensoryNote::where('lot_id', $lotId)->count())->toBe(2);
    });
});

// ─── Tier 1: Tenant Isolation ──────────────────────────────────

it('prevents cross-tenant sensory note access', function () {
    $tenantA = Tenant::create(['name' => 'Sensory Alpha', 'slug' => 'sensory-iso-a', 'plan' => 'pro']);
    $tenantB = Tenant::create(['name' => 'Sensory Beta', 'slug' => 'sensory-iso-b', 'plan' => 'pro']);

    $tenantA->run(function () {
        $lot = createSensoryLot();
        $user = User::create([
            'name' => 'Alpha Taster', 'email' => 'a@example.com',
            'password' => 'SecurePass123!', 'role' => 'winemaker', 'is_active' => true,
        ]);
        SensoryNote::create([
            'lot_id' => $lot->id,
            'taster_id' => $user->id,
            'date' => '2024-11-15',
            'rating' => 4.0,
            'rating_scale' => 'five_point',
            'nose_notes' => 'Alpha notes',
        ]);
    });

    $tenantB->run(function () {
        expect(SensoryNote::count())->toBe(0);
        expect(Lot::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(SensoryNote::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD API ──────────────────────────────────────────

it('lists sensory notes for a lot', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-list');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = createSensoryLot();
        $lotId = $lot->id;
        $user = User::first();

        SensoryNote::create([
            'lot_id' => $lot->id, 'taster_id' => $user->id,
            'date' => '2024-11-10', 'rating' => 3.5, 'rating_scale' => 'five_point',
            'nose_notes' => 'First tasting',
        ]);
        SensoryNote::create([
            'lot_id' => $lot->id, 'taster_id' => $user->id,
            'date' => '2024-11-20', 'rating' => 4.0, 'rating_scale' => 'five_point',
            'palate_notes' => 'Second tasting',
        ]);
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    // Most recent first
    expect($response->json('data.0.date'))->toBe('2024-11-20');
});

it('shows a single sensory note with taster info', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-show');

    $noteId = null;
    $lotId = null;
    $tenant->run(function () use (&$noteId, &$lotId) {
        $lot = createSensoryLot();
        $lotId = $lot->id;
        $user = User::first();

        $note = SensoryNote::create([
            'lot_id' => $lot->id, 'taster_id' => $user->id,
            'date' => '2024-11-15', 'rating' => 4.5, 'rating_scale' => 'five_point',
            'nose_notes' => 'Complex and layered',
            'palate_notes' => 'Silky tannins',
            'overall_notes' => 'Reserve quality',
        ]);
        $noteId = $note->id;
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/sensory-notes/{$noteId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $data = $response->json('data');
    expect($data['nose_notes'])->toBe('Complex and layered');
    expect($data['palate_notes'])->toBe('Silky tannins');
    expect($data['overall_notes'])->toBe('Reserve quality');
    expect($data['taster']['name'])->toBe('Test Winemaker');
    expect($data['lot']['name'])->toBe('Sensory Test Lot');
});

it('creates a sensory note with all fields', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-create');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot(['name' => 'Estate Chard 2024'])->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 4.2,
        'rating_scale' => 'five_point',
        'nose_notes' => 'Citrus and mineral',
        'palate_notes' => 'Clean acidity, medium body',
        'overall_notes' => 'Excellent for varietal',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $data = $response->json('data');
    expect($data['lot']['name'])->toBe('Estate Chard 2024');
    expect($data['taster']['name'])->toBe('Test Winemaker');
    expect($data['nose_notes'])->toBe('Citrus and mineral');
});

// ─── Tier 2: Validation ─────────────────────────────────────────

it('rejects sensory note with invalid rating scale', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-val-scale');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 4.0,
        'rating_scale' => 'ten_point',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects sensory note without date', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-val-date');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'rating' => 4.0,
        'nose_notes' => 'Missing date',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

// ─── Tier 2: RBAC ──────────────────────────────────────────────

it('winemaker can create sensory notes', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-rbac-wm');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'nose_notes' => 'Winemaker notes',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('cellar_hand cannot create sensory notes', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-rbac-ch', 'cellar_hand');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'nose_notes' => 'Cellar hand notes',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read_only cannot create but can list sensory notes', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-rbac-ro', 'read_only');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = createSensoryLot();
        $lotId = $lot->id;
        $user = User::first();
        SensoryNote::create([
            'lot_id' => $lot->id, 'taster_id' => $user->id,
            'date' => '2024-11-15', 'rating' => 4.0, 'rating_scale' => 'five_point',
        ]);
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Can list
    test()->getJson("/api/v1/lots/{$lotId}/sensory-notes", $headers)->assertOk();

    // Cannot create
    test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'nose_notes' => 'Read only notes',
    ], $headers)->assertStatus(403);
});

// ─── Tier 2: API Envelope ──────────────────────────────────────

it('returns sensory note responses in the standard API envelope format', function () {
    [$tenant, $token] = createSensoryTestTenant('sensory-env');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lotId = createSensoryLot()->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/sensory-notes", [
        'date' => '2024-11-15',
        'rating' => 4.0,
        'rating_scale' => 'five_point',
        'nose_notes' => 'Envelope test',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'taster_id', 'date', 'rating', 'rating_scale', 'nose_notes', 'palate_notes', 'overall_notes'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});
