<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WorkOrder;
use App\Models\WorkOrderTemplate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createWorkOrderTestTenant(string $slug = 'wo-winery', string $role = 'winemaker'): array
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

// ─── Tier 1: Event Log Writes ────────────────────────────────────

it('writes a work_order_created event when a work order is created', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $response = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
        'priority' => 'high',
        'notes' => 'Lot needs extra extraction',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $workOrderId = $response->json('data.id');

    $tenant->run(function () use ($workOrderId) {
        $event = Event::where('entity_type', 'work_order')
            ->where('entity_id', $workOrderId)
            ->where('operation_type', 'work_order_created')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['operation_type'])->toBe('Pump Over');
        expect($event->payload['due_date'])->toBe('2026-03-15');
        expect($event->payload['priority'])->toBe('high');
        expect($event->performed_by)->not->toBeNull();
    });
});

it('writes a work_order_completed event when completed', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    // Create a lot for the work order
    $lotResponse = test()->postJson('/api/v1/lots', [
        'name' => '2024 Cab Sauv',
        'variety' => 'Cabernet Sauvignon',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 1500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $lotId = $lotResponse->json('data.id');

    // Create a work order tied to the lot
    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Punch Down',
        'lot_id' => $lotId,
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $workOrderId = $createResponse->json('data.id');

    // Complete the work order
    test()->postJson("/api/v1/work-orders/{$workOrderId}/complete", [
        'completion_notes' => 'Done, nice cap breakup',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    $tenant->run(function () use ($workOrderId, $lotId) {
        // Work order completed event
        $woEvent = Event::where('entity_type', 'work_order')
            ->where('entity_id', $workOrderId)
            ->where('operation_type', 'work_order_completed')
            ->first();

        expect($woEvent)->not->toBeNull();
        expect($woEvent->payload['operation_type'])->toBe('Punch Down');
        expect($woEvent->payload['lot_id'])->toBe($lotId);
        expect($woEvent->payload['completion_notes'])->toBe('Done, nice cap breakup');

        // Domain event on the lot timeline
        $lotEvent = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'punch_down_completed')
            ->first();

        expect($lotEvent)->not->toBeNull();
        expect($lotEvent->payload['work_order_id'])->toBe($workOrderId);
    });
});

// ─── Tier 2: CRUD Operations ─────────────────────────────────────

it('creates a work order with all fields', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    // Create a lot and vessel for association
    $lotResponse = test()->postJson('/api/v1/lots', [
        'name' => 'Test Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $lotId = $lotResponse->json('data.id');

    $vesselResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 2000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $vesselId = $vesselResponse->json('data.id');

    $response = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'lot_id' => $lotId,
        'vessel_id' => $vesselId,
        'due_date' => '2026-03-20',
        'priority' => 'high',
        'notes' => 'Three pump overs today',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.operation_type', 'Pump Over')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.due_date', '2026-03-20')
        ->assertJsonPath('data.notes', 'Three pump overs today')
        ->assertJsonPath('data.lot.id', $lotId)
        ->assertJsonPath('data.lot.name', 'Test Lot')
        ->assertJsonPath('data.vessel.id', $vesselId)
        ->assertJsonPath('data.vessel.name', 'T-001')
        ->assertJsonStructure([
            'data' => [
                'id', 'operation_type', 'status', 'priority', 'due_date',
                'notes', 'lot', 'vessel', 'assigned_to', 'completed_at',
                'completion_notes', 'created_at', 'updated_at',
            ],
        ]);
});

it('lists work orders with pagination', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    foreach (['Pump Over', 'Punch Down', 'Add SO2'] as $op) {
        test()->postJson('/api/v1/work-orders', [
            'operation_type' => $op,
            'due_date' => '2026-03-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    }

    $response = test()->getJson('/api/v1/work-orders', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('filters work orders by status', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    // Create two work orders
    $wo1 = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->json('data.id');

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Rack',
        'due_date' => '2026-03-16',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Complete the first one
    test()->postJson("/api/v1/work-orders/{$wo1}/complete", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/work-orders?status=pending', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters work orders by due date range', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-10',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Rack',
        'due_date' => '2026-03-20',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Add SO2',
        'due_date' => '2026-04-01',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/work-orders?due_from=2026-03-01&due_to=2026-03-31', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('shows work order detail with relationships', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Sample',
        'due_date' => '2026-03-15',
        'priority' => 'low',
        'notes' => 'Pull barrel sample for tasting',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $woId = $createResponse->json('data.id');

    $response = test()->getJson("/api/v1/work-orders/{$woId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $woId)
        ->assertJsonPath('data.operation_type', 'Sample')
        ->assertJsonPath('data.priority', 'low')
        ->assertJsonPath('data.notes', 'Pull barrel sample for tasting');
});

it('updates work order status and priority', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Transfer',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $woId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/work-orders/{$woId}", [
        'status' => 'in_progress',
        'priority' => 'high',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.priority', 'high');
});

// ─── Tier 2: Bulk Creation ──────────────────────────────────────

it('bulk creates work orders across multiple lots', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    // Create two lots
    $lot1 = test()->postJson('/api/v1/lots', [
        'name' => 'Lot A',
        'variety' => 'Pinot Noir',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->json('data.id');

    $lot2 = test()->postJson('/api/v1/lots', [
        'name' => 'Lot B',
        'variety' => 'Chardonnay',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 400,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->json('data.id');

    $response = test()->postJson('/api/v1/work-orders/bulk', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
        'priority' => 'normal',
        'targets' => [
            ['lot_id' => $lot1],
            ['lot_id' => $lot2],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.count', 2);

    // Verify both work orders exist
    $listResponse = test()->getJson('/api/v1/work-orders', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $listResponse->assertJsonCount(2, 'data');
});

// ─── Tier 2: Templates ──────────────────────────────────────────

it('lists active work order templates', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    // Seed some templates
    $tenant->run(function () {
        WorkOrderTemplate::create([
            'name' => 'Pump Over',
            'operation_type' => 'Pump Over',
            'default_notes' => '30 minutes, gentle pump',
            'is_active' => true,
        ]);
        WorkOrderTemplate::create([
            'name' => 'Retired Op',
            'operation_type' => 'Legacy Op',
            'is_active' => false,
        ]);
    });

    $response = test()->getJson('/api/v1/work-orders/templates', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    // Only active templates returned
    $templates = $response->json('data');
    expect(count($templates))->toBe(1);
    expect($templates[0]['name'])->toBe('Pump Over');
});

// ─── Tier 2: Calendar View ──────────────────────────────────────

it('returns calendar view grouped by date', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Punch Down',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Add SO2',
        'due_date' => '2026-03-16',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/work-orders/calendar?from=2026-03-01&to=2026-03-31', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveKey('2026-03-15');
    expect($data)->toHaveKey('2026-03-16');
    expect(count($data['2026-03-15']))->toBe(2);
    expect(count($data['2026-03-16']))->toBe(1);
});

// ─── Tier 2: Completion Flow ────────────────────────────────────

it('completes a work order and records completion details', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $woId = $createResponse->json('data.id');

    $response = test()->postJson("/api/v1/work-orders/{$woId}/complete", [
        'completion_notes' => '30 min pump, good color extraction',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completion_notes', '30 min pump, good color extraction');

    // completed_at and completed_by should be set
    expect($response->json('data.completed_at'))->not->toBeNull();
    expect($response->json('data.completed_by'))->not->toBeNull();
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects work order creation with missing operation_type', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $response = test()->postJson('/api/v1/work-orders', [
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('operation_type');
});

it('rejects invalid status on update', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $woId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/work-orders/{$woId}", [
        'status' => 'exploded',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects invalid priority', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $response = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'priority' => 'critical',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('priority');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('cellar_hand can complete but not create work orders', function () {
    [$tenant, $wmToken] = createWorkOrderTestTenant('rbac-wo', 'winemaker');

    // Winemaker creates a work order
    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$wmToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $woId = $createResponse->json('data.id');

    // Create a cellar_hand user
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Cellar Hand',
            'email' => 'cellar@example.com',
            'password' => 'SecurePass123!',
            'role' => 'cellar_hand',
            'is_active' => true,
        ]);
        $user->assignRole('cellar_hand');
    });

    $chLogin = test()->postJson('/api/v1/auth/login', [
        'email' => 'cellar@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], ['X-Tenant-ID' => $tenant->id]);
    $chToken = $chLogin->json('data.token');

    // Reset auth guard so Sanctum re-resolves the user from the new token
    app('auth')->forgetGuards();

    // Cellar hand CANNOT create work orders
    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Unauthorized',
    ], [
        'Authorization' => "Bearer {$chToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);

    // Cellar hand CAN complete work orders
    test()->postJson("/api/v1/work-orders/{$woId}/complete", [
        'completion_notes' => 'Done',
    ], [
        'Authorization' => "Bearer {$chToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

it('read-only users cannot create or complete work orders', function () {
    // First create a work order as winemaker that we can try to complete
    [$tenant, $wmToken] = createWorkOrderTestTenant('rbac-ro-wo', 'winemaker');

    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
        'due_date' => '2026-03-15',
    ], [
        'Authorization' => "Bearer {$wmToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $woId = $createResponse->json('data.id');

    // Create a read_only user
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Read Only',
            'email' => 'readonly@example.com',
            'password' => 'SecurePass123!',
            'role' => 'read_only',
            'is_active' => true,
        ]);
        $user->assignRole('read_only');
    });

    $roLogin = test()->postJson('/api/v1/auth/login', [
        'email' => 'readonly@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], ['X-Tenant-ID' => $tenant->id]);
    $roToken = $roLogin->json('data.token');

    // Reset auth guard so Sanctum re-resolves the user from the new token
    app('auth')->forgetGuards();

    // read_only CANNOT create work orders
    test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Unauthorized',
    ], [
        'Authorization' => "Bearer {$roToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);

    // read_only CANNOT complete work orders
    test()->postJson("/api/v1/work-orders/{$woId}/complete", [
        'completion_notes' => 'Should not work',
    ], [
        'Authorization' => "Bearer {$roToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view work orders', function () {
    [$tenant, $wmToken] = createWorkOrderTestTenant('rbac-view-wo', 'winemaker');

    $createResponse = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
    ], [
        'Authorization' => "Bearer {$wmToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);
    $woId = $createResponse->json('data.id');

    // Create read-only user
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Reader',
            'email' => 'reader@example.com',
            'password' => 'SecurePass123!',
            'role' => 'read_only',
            'is_active' => true,
        ]);
        $user->assignRole('read_only');
    });

    $readerLogin = test()->postJson('/api/v1/auth/login', [
        'email' => 'reader@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], ['X-Tenant-ID' => $tenant->id]);
    $readerToken = $readerLogin->json('data.token');

    test()->getJson('/api/v1/work-orders', [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    test()->getJson("/api/v1/work-orders/{$woId}", [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

// ─── Tier 2: API Envelope ────────────────────────────────────────

it('returns work order responses in the standard API envelope format', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    $response = test()->postJson('/api/v1/work-orders', [
        'operation_type' => 'Pump Over',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data',
            'meta',
            'errors',
        ]);

    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to work orders', function () {
    [$tenant, $token] = createWorkOrderTestTenant();

    test()->getJson('/api/v1/work-orders', [
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(401);
});

// ─── Tier 1: Tenant Isolation ───────────────────────────────────

it('prevents cross-tenant work order data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'wo-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'wo-iso-beta',
        'plan' => 'pro',
    ]);

    $tenantA->run(function () {
        WorkOrder::create([
            'operation_type' => 'Alpha Pump Over',
            'due_date' => '2026-03-15',
        ]);
    });

    $tenantB->run(function () {
        WorkOrder::create([
            'operation_type' => 'Beta Rack',
            'due_date' => '2026-03-16',
        ]);
    });

    $tenantA->run(function () {
        $orders = WorkOrder::all();
        expect($orders)->toHaveCount(1);
        expect($orders->first()->operation_type)->toBe('Alpha Pump Over');
    });

    $tenantB->run(function () {
        $orders = WorkOrder::all();
        expect($orders)->toHaveCount(1);
        expect($orders->first()->operation_type)->toBe('Beta Rack');
    });

    $alphaId = null;
    $tenantA->run(function () use (&$alphaId) {
        $alphaId = WorkOrder::first()->id;
    });

    $tenantB->run(function () use ($alphaId) {
        expect(WorkOrder::find($alphaId))->toBeNull();
    });
});
