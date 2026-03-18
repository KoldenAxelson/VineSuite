<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant and optionally run a callback in its context.
 */
function createTestTenantForEventLog(string $slug = 'event-winery', ?Closure $callback = null): Tenant
{
    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'basic',
    ]);

    if ($callback) {
        $tenant->run($callback);
    }

    return $tenant;
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

// ─── Event Creation ─────────────────────────────────────────────

it('creates an event with all required fields', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $entityId = Str::uuid()->toString();
        $event = $logger->log(
            entityType: 'lot',
            entityId: $entityId,
            operationType: 'addition',
            payload: [
                'volume_gallons' => 500,
                'grape_variety' => 'Cabernet Sauvignon',
                'vineyard' => 'Estate Block A',
            ],
            performedAt: now(),
        );

        expect($event)->toBeInstanceOf(Event::class);
        expect($event->id)->not->toBeNull();
        expect($event->entity_type)->toBe('lot');
        expect($event->entity_id)->toBe($entityId);
        expect($event->operation_type)->toBe('addition');
        expect($event->payload)->toBeArray();
        expect($event->payload['volume_gallons'])->toBe(500);
        expect($event->payload['grape_variety'])->toBe('Cabernet Sauvignon');
        expect($event->performed_at)->not->toBeNull();
        expect($event->created_at)->not->toBeNull();
    });
});

it('stores payload as JSONB and it is queryable', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $entityId = Str::uuid()->toString();
        $logger->log(
            entityType: 'lot',
            entityId: $entityId,
            operationType: 'addition',
            payload: ['volume_gallons' => 500, 'grape_variety' => 'Pinot Noir'],
            performedAt: now(),
        );

        // Query JSONB payload using PostgreSQL operator
        $results = Event::whereRaw("payload->>'grape_variety' = ?", ['Pinot Noir'])->get();
        expect($results)->toHaveCount(1);
        expect($results->first()->payload['volume_gallons'])->toBe(500);
    });
});

it('accepts performed_at as a client-provided timestamp', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $clientTimestamp = now()->subHours(3);

        $event = $logger->log(
            entityType: 'vessel',
            entityId: Str::uuid()->toString(),
            operationType: 'transfer',
            payload: ['from_vessel' => 'Tank A', 'to_vessel' => 'Tank B'],
            performedAt: $clientTimestamp,
        );

        expect($event->performed_at->format('Y-m-d H:i'))
            ->toBe($clientTimestamp->format('Y-m-d H:i'));
    });
});

it('sets synced_at for mobile-synced events', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $event = $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 100],
            performedAt: now()->subMinutes(30),
            deviceId: 'iphone-cellar-001',
            isSynced: true,
        );

        expect($event->synced_at)->not->toBeNull();
        expect($event->device_id)->toBe('iphone-cellar-001');
    });
});

it('leaves synced_at null for locally-created events', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $event = $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 200],
            performedAt: now(),
        );

        expect($event->synced_at)->toBeNull();
    });
});

it('links event to performing user', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Winemaker Joe',
            'email' => 'joe@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');

        $logger = app(EventLogger::class);

        $event = $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 300],
            performedBy: $user->id,
            performedAt: now(),
        );

        expect($event->performed_by)->toBe($user->id);
        expect($event->performer->name)->toBe('Winemaker Joe');
    });
});

// ─── Idempotency ────────────────────────────────────────────────

it('enforces idempotency key uniqueness — returns existing event on duplicate', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $entityId = Str::uuid()->toString();
        $idempotencyKey = 'mobile-sync-abc-123';

        // First write
        $event1 = $logger->log(
            entityType: 'lot',
            entityId: $entityId,
            operationType: 'addition',
            payload: ['volume_gallons' => 500],
            performedAt: now(),
            idempotencyKey: $idempotencyKey,
        );

        // Duplicate write — should return same event, not create new one
        $event2 = $logger->log(
            entityType: 'lot',
            entityId: $entityId,
            operationType: 'addition',
            payload: ['volume_gallons' => 999], // different payload
            performedAt: now(),
            idempotencyKey: $idempotencyKey,
        );

        expect($event2->id)->toBe($event1->id);
        expect($event2->payload['volume_gallons'])->toBe(500); // original payload preserved
        expect(Event::count())->toBe(1);
    });
});

it('allows null idempotency keys (multiple events without keys)', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 100],
            performedAt: now(),
        );

        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 200],
            performedAt: now(),
        );

        expect(Event::count())->toBe(2);
    });
});

// ─── Immutability ───────────────────────────────────────────────

it('prevents UPDATE on events table via database trigger', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $event = $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 500],
            performedAt: now(),
        );

        // Attempt to update — should throw exception from trigger
        expect(fn () => DB::table('events')
            ->where('id', $event->id)
            ->update(['operation_type' => 'tampered']))
            ->toThrow(QueryException::class);

        // Verify the event is unchanged
        $fresh = Event::find($event->id);
        expect($fresh->operation_type)->toBe('addition');
    });
});

it('prevents DELETE on events table via database trigger', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $event = $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 500],
            performedAt: now(),
        );

        // Attempt to delete — should throw exception from trigger
        expect(fn () => DB::table('events')
            ->where('id', $event->id)
            ->delete())
            ->toThrow(QueryException::class);

        // Verify the event still exists
        expect(Event::count())->toBe(1);
    });
});

// ─── Query Methods ──────────────────────────────────────────────

it('retrieves entity event stream in chronological order', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        $lotId = Str::uuid()->toString();

        // Create events in reverse chronological order
        $logger->log(
            entityType: 'lot',
            entityId: $lotId,
            operationType: 'bottling',
            payload: ['bottles' => 240],
            performedAt: now()->subHours(1),
        );

        $logger->log(
            entityType: 'lot',
            entityId: $lotId,
            operationType: 'addition',
            payload: ['volume_gallons' => 500],
            performedAt: now()->subDays(30),
        );

        $logger->log(
            entityType: 'lot',
            entityId: $lotId,
            operationType: 'transfer',
            payload: ['to_vessel' => 'Barrel 1'],
            performedAt: now()->subDays(15),
        );

        // Also create an event for a different entity (should not appear)
        $logger->log(
            entityType: 'vessel',
            entityId: Str::uuid()->toString(),
            operationType: 'cleaning',
            payload: ['method' => 'steam'],
            performedAt: now(),
        );

        $stream = $logger->getEntityStream('lot', $lotId);

        expect($stream)->toHaveCount(3);
        expect($stream[0]->operation_type)->toBe('addition');
        expect($stream[1]->operation_type)->toBe('transfer');
        expect($stream[2]->operation_type)->toBe('bottling');
    });
});

it('retrieves events by operation type within a time range', function () {
    $tenant = createTestTenantForEventLog();

    $tenant->run(function () {
        $logger = app(EventLogger::class);

        // Create addition events across different dates
        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 100],
            performedAt: now()->subDays(45), // outside range
        );

        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 200],
            performedAt: now()->subDays(15), // inside range
        );

        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 300],
            performedAt: now()->subDays(5), // inside range
        );

        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'transfer', // different type
            payload: [],
            performedAt: now()->subDays(10),
        );

        $events = $logger->getByOperationType(
            'addition',
            now()->subDays(30),
            now(),
        );

        expect($events)->toHaveCount(2);
        expect($events[0]->payload['volume_gallons'])->toBe(200);
        expect($events[1]->payload['volume_gallons'])->toBe(300);
    });
});

// ─── Tenant Isolation ───────────────────────────────────────────

it('isolates events between tenants', function () {
    $tenant1 = createTestTenantForEventLog('winery-alpha');
    $tenant2 = createTestTenantForEventLog('winery-beta');

    // Create event in tenant 1
    $tenant1->run(function () {
        $logger = app(EventLogger::class);
        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 500],
            performedAt: now(),
        );
    });

    // Create event in tenant 2
    $tenant2->run(function () {
        $logger = app(EventLogger::class);
        $logger->log(
            entityType: 'lot',
            entityId: Str::uuid()->toString(),
            operationType: 'addition',
            payload: ['volume_gallons' => 1000],
            performedAt: now(),
        );
    });

    // Verify isolation
    $tenant1->run(function () {
        expect(Event::count())->toBe(1);
        expect(Event::first()->payload['volume_gallons'])->toBe(500);
    });

    $tenant2->run(function () {
        expect(Event::count())->toBe(1);
        expect(Event::first()->payload['volume_gallons'])->toBe(1000);
    });
});
