<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventProcessor;
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
        'plan' => 'basic',
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

    return [$tenant, $loginResponse->json('data.token')];
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
        ->assertJsonPath('meta.accepted', 3)
        ->assertJsonPath('meta.skipped', 0)
        ->assertJsonPath('meta.failed', 0)
        ->assertJsonCount(3, 'data');

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
    ], $headers)->assertOk()->assertJsonPath('meta.accepted', 1);

    // Second sync with same idempotency key
    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [$event],
    ], $headers);

    $response->assertOk()
        ->assertJsonPath('meta.accepted', 0)
        ->assertJsonPath('meta.skipped', 1);

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
        ->assertJsonPath('meta.accepted', 1)
        ->assertJsonPath('meta.skipped', 1);

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
    $response1->assertOk()->assertJsonPath('meta.accepted', 2);

    // Identical second call
    $response2 = $this->postJson('/api/v1/events/sync', ['events' => $events], $headers);
    $response2->assertOk()
        ->assertJsonPath('meta.accepted', 0)
        ->assertJsonPath('meta.skipped', 2);

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

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('events.0.performed_at');
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

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('events.0.performed_at');
});

it('rejects empty events array', function () {
    [$tenant, $token] = createSyncTestTenant();

    $response = $this->postJson('/api/v1/events/sync', [
        'events' => [],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('events');
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

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('events.0.entity_id')
        ->toContain('events.0.operation_type')
        ->toContain('events.0.payload')
        ->toContain('events.0.performed_at')
        ->toContain('events.0.idempotency_key');
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

// ─── Partial Batch Failure ──────────────────────────────────────

it('handles partial batch failure gracefully via EventProcessor', function () {
    [$tenant, $token] = createSyncTestTenant('partial-fail');

    $tenant->run(function () {
        $processor = app(EventProcessor::class);
        $userId = User::first()->id;

        $events = [
            // Event 1: valid
            [
                'entity_type' => 'lot',
                'entity_id' => Str::uuid()->toString(),
                'operation_type' => 'addition',
                'payload' => ['volume_gallons' => 500],
                'performed_at' => now()->subMinutes(10)->toIso8601String(),
                'idempotency_key' => 'partial-test-1',
                'device_id' => 'test-device',
            ],
            // Event 2: null entity_id will cause a DB not-null constraint violation
            [
                'entity_type' => 'lot',
                'entity_id' => null,
                'operation_type' => 'addition',
                'payload' => ['volume_gallons' => 200],
                'performed_at' => now()->subMinutes(5)->toIso8601String(),
                'idempotency_key' => 'partial-test-2',
                'device_id' => 'test-device',
            ],
            // Event 3: valid
            [
                'entity_type' => 'vessel',
                'entity_id' => Str::uuid()->toString(),
                'operation_type' => 'transfer',
                'payload' => ['volume_gallons' => 300],
                'performed_at' => now()->subMinutes(3)->toIso8601String(),
                'idempotency_key' => 'partial-test-3',
                'device_id' => 'test-device',
            ],
        ];

        $result = $processor->processBatch($events, $userId);

        // Event 1 and 3 should succeed, event 2 should fail
        expect($result['accepted'])->toBe(2);
        expect($result['failed'])->toBe(1);
        expect($result['skipped'])->toBe(0);

        // Verify the failed event has status 'failed' and an error message
        $failedResult = collect($result['results'])->firstWhere('status', 'failed');
        expect($failedResult)->not->toBeNull();
        expect($failedResult['index'])->toBe(1);
        expect($failedResult['event_id'])->toBeNull();
        expect($failedResult['error'])->not->toBeEmpty();

        // Verify only 2 events in the DB (the failed one rolled back)
        expect(Event::count())->toBe(2);
    });
});
