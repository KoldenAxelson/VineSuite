<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with an owner user and return [tenant, token].
 */
function createSyncTestTenant(string $slug = 'sync-winery'): array
{
    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'starter',
    ]);

    // Create owner user inside tenant
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    // Login to get token
    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('token')];
}

/*
 * Helper: build a valid event payload for sync.
 */
function buildSyncEvent(array $overrides = []): array
{
    return array_merge([
        'entity_type' => 'lot',
        'entity_id' => Str::uuid()->toString(),
        'operation_type' => 'addition',
        'payload' => ['volume_gallons' => 500, 'grape_variety' => 'Cabernet Sauvignon'],
        'performed_at' => now()->subMinutes(30)->toIso8601String(),
        'idempotency_key' => Str::uuid()->toString(),
        'device_id' => 'iphone-cellar-001',
    ], $overrides);
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

// ─── Basic Sync ─────────────────────────────────────────────────

it('accepts a batch of events and returns per-event status', function () {
    [$tenant, $token] = createSyncTestTenant();

    $events = [
        buildSyncEvent(),
        buildSyncEvent(['entity_type' => 'vessel', 'operation_type' => 'transfer']),
        buildSyncEvent(['operation_type' => 'bottling']),
    ];

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => $events,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('accepted', 3)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('failed', 0)
        ->assertJsonCount(3, 'results');

    // Verify events are in the database
    $tenant->run(function () {
        expect(Event::count())->toBe(3);
    });
});

it('sets synced_at on all received events', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [buildSyncEvent()],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $tenant->run(function () {
        $event = Event::first();
        expect($event->synced_at)->not->toBeNull();
        expect($event->device_id)->toBe('iphone-cellar-001');
    });
});

it('links events to the authenticated user', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [buildSyncEvent()],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $tenant->run(function () {
        $event = Event::first();
        expect($event->performed_by)->not->toBeNull();
        expect($event->performer->email)->toBe('owner@example.com');
    });
});

// ─── Idempotency ────────────────────────────────────────────────

it('skips events with duplicate idempotency keys', function () {
    [$tenant, $token] = createSyncTestTenant();

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $event = buildSyncEvent(['idempotency_key' => 'dup-key-123']);

    // First sync
    $this->postJson('/api/v1/events/sync', [
        'events' => [$event],
    ], $headers)->assertOk()->assertJsonPath('accepted', 1);

    // Second sync with same idempotency key
    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [$event],
    ], $headers);

    $response->assertOk()
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('skipped', 1);

    // Only one event in DB
    $tenant->run(function () {
        expect(Event::count())->toBe(1);
    });
});

it('handles mixed new and duplicate events in same batch', function () {
    [$tenant, $token] = createSyncTestTenant();

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $existingEvent = buildSyncEvent(['idempotency_key' => 'existing-key']);

    // First sync — create one event
    $this->postJson('/api/v1/events/sync', [
        'events' => [$existingEvent],
    ], $headers)->assertOk();

    // Second sync — one duplicate + one new
    $newEvent = buildSyncEvent(['idempotency_key' => 'new-key']);

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [$existingEvent, $newEvent],
    ], $headers);

    $response->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('skipped', 1);

    $tenant->run(function () {
        expect(Event::count())->toBe(2);
    });
});

it('is fully idempotent — calling twice produces same result', function () {
    [$tenant, $token] = createSyncTestTenant();

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $events = [
        buildSyncEvent(['idempotency_key' => 'idem-1']),
        buildSyncEvent(['idempotency_key' => 'idem-2']),
    ];

    // First call
    $response1 = $this->postJson('/api/v1/events/sync', ['events' => $events], $headers);
    $response1->assertOk()->assertJsonPath('accepted', 2);

    // Identical second call
    $response2 = $this->postJson('/api/v1/events/sync', ['events' => $events], $headers);
    $response2->assertOk()
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('skipped', 2);

    // Still only 2 events
    $tenant->run(function () {
        expect(Event::count())->toBe(2);
    });
});

// ─── Validation ─────────────────────────────────────────────────

it('rejects events with performed_at more than 30 days in the past', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [
            buildSyncEvent(['performed_at' => now()->subDays(31)->toIso8601String()]),
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('events.0.performed_at');
});

it('rejects events with performed_at in the future', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [
            buildSyncEvent(['performed_at' => now()->addHours(2)->toIso8601String()]),
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('events.0.performed_at');
});

it('rejects empty events array', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('events');
});

it('validates required fields on each event', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [
            ['entity_type' => 'lot'], // missing most fields
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'events.0.entity_id',
            'events.0.operation_type',
            'events.0.payload',
            'events.0.performed_at',
            'events.0.idempotency_key',
        ]);
});

// ─── Authentication ─────────────────────────────────────────────

it('requires authentication', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [buildSyncEvent()],
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(401);
});

// ─── performed_at Preservation ──────────────────────────────────

it('preserves client-provided performed_at timestamp', function () {
    [$tenant, $token] = createSyncTestTenant();

    $clientTime = now()->subHours(6)->startOfMinute()->toIso8601String();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [buildSyncEvent(['performed_at' => $clientTime])],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $tenant->run(function () use ($clientTime) {
        $event = Event::first();
        expect($event->performed_at->toIso8601String())->toBe($clientTime);
    });
});
