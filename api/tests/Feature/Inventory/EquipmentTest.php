<?php

declare(strict_types=1);

use App\Models\Equipment;
use App\Models\Event;
use App\Models\MaintenanceLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createEquipmentTestTenant(string $slug = 'eq-winery', string $role = 'admin'): array
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

// ─── Tier 1: Event Logging ──────────────────────────────────────

describe('equipment event logging', function () {
    it('writes equipment_created event with inventory source', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-evt-create');

        $response = test()->postJson('/api/v1/equipment', [
            'name' => 'SS Fermentation Tank #1',
            'equipment_type' => 'tank',
            'serial_number' => 'TK-2024-0001',
            'status' => 'operational',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $itemId = $response->json('data.id');

        $tenant->run(function () use ($itemId) {
            $event = Event::where('entity_id', $itemId)
                ->where('operation_type', 'equipment_created')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->entity_type)->toBe('equipment');
            expect($event->payload['name'])->toBe('SS Fermentation Tank #1');
            expect($event->payload['equipment_type'])->toBe('tank');
            expect($event->payload['serial_number'])->toBe('TK-2024-0001');
        });
    });

    it('writes equipment_updated event with inventory source', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-evt-update');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = Equipment::create([
                'name' => 'Peristaltic Pump P-1',
                'equipment_type' => 'pump',
                'status' => 'operational',
            ]);
            $itemId = $item->id;
        });

        test()->putJson("/api/v1/equipment/{$itemId}", [
            'status' => 'maintenance',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($itemId) {
            $event = Event::where('entity_id', $itemId)
                ->where('operation_type', 'equipment_updated')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['status'])->toBe('maintenance');
        });
    });

    it('writes equipment_maintenance_logged event', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-evt-maint');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'pH Meter Hanna HI2020',
                'equipment_type' => 'lab_instrument',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        $response = test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'calibration',
            'performed_date' => '2026-03-15',
            'description' => '2-point calibration with pH 4.01 and 7.00 buffers',
            'passed' => true,
            'next_due_date' => '2026-04-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $tenant->run(function () use ($equipmentId) {
            $event = Event::where('entity_id', $equipmentId)
                ->where('operation_type', 'equipment_maintenance_logged')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['maintenance_type'])->toBe('calibration');
            expect($event->payload['equipment_name'])->toBe('pH Meter Hanna HI2020');
            expect($event->payload['passed'])->toBeTrue();
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('equipment tenant isolation', function () {
    it('prevents cross-tenant equipment access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'eq-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'eq-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            Equipment::create([
                'name' => 'Alpha Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
        });

        $tenantB->run(function () {
            expect(Equipment::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(Equipment::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 1: Data Integrity ─────────────────────────────────────

describe('equipment data integrity', function () {
    it('identifies equipment with overdue maintenance', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-overdue');

        $tenant->run(function () {
            Equipment::create([
                'name' => 'Overdue Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
                'next_maintenance_due' => now()->subDays(10),
            ]);

            Equipment::create([
                'name' => 'OK Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
                'next_maintenance_due' => now()->addDays(30),
            ]);

            Equipment::create([
                'name' => 'No Schedule',
                'equipment_type' => 'pump',
                'status' => 'operational',
            ]);

            $overdue = Equipment::maintenanceDue()->get();
            expect($overdue)->toHaveCount(1);
            expect($overdue->first()->name)->toBe('Overdue Tank');
        });
    });

    it('isMaintenanceOverdue returns correct boolean', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-overdue-helper');

        $tenant->run(function () {
            $overdue = Equipment::create([
                'name' => 'Late Pump',
                'equipment_type' => 'pump',
                'status' => 'operational',
                'next_maintenance_due' => now()->subDays(5),
            ]);
            expect($overdue->isMaintenanceOverdue())->toBeTrue();

            $ok = Equipment::create([
                'name' => 'OK Pump',
                'equipment_type' => 'pump',
                'status' => 'operational',
                'next_maintenance_due' => now()->addDays(30),
            ]);
            expect($ok->isMaintenanceOverdue())->toBeFalse();

            $noSchedule = Equipment::create([
                'name' => 'Unscheduled',
                'equipment_type' => 'filter',
                'status' => 'operational',
            ]);
            expect($noSchedule->isMaintenanceOverdue())->toBeFalse();
        });
    });

    it('maintenance log updates equipment next_maintenance_due', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-next-due');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'pH Meter',
                'equipment_type' => 'lab_instrument',
                'status' => 'operational',
                'next_maintenance_due' => '2026-03-01',
            ]);
            $equipmentId = $item->id;
        });

        test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'calibration',
            'performed_date' => '2026-03-15',
            'passed' => true,
            'next_due_date' => '2026-04-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);

        $tenant->run(function () use ($equipmentId) {
            $equipment = Equipment::find($equipmentId);
            expect($equipment->next_maintenance_due->toDateString())->toBe('2026-04-15');
        });
    });

    it('maintenance log with cascade delete on equipment', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-cascade');

        $tenant->run(function () {
            $equipment = Equipment::create([
                'name' => 'Temp Press',
                'equipment_type' => 'press',
                'status' => 'operational',
            ]);

            MaintenanceLog::create([
                'equipment_id' => $equipment->id,
                'maintenance_type' => 'cleaning',
                'performed_date' => now(),
            ]);

            expect(MaintenanceLog::count())->toBe(1);

            $equipment->delete();

            expect(MaintenanceLog::count())->toBe(0);
        });
    });
})->group('inventory');

// ─── Tier 2: CRUD ────────────────────────────────────────────────

describe('equipment CRUD', function () {
    it('creates equipment with all fields', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-crud-all');

        $response = test()->postJson('/api/v1/equipment', [
            'name' => 'Crossflow Filter CF-500',
            'equipment_type' => 'filter',
            'serial_number' => 'CF-2023-500',
            'manufacturer' => 'Pall Corporation',
            'model_number' => 'OENOFLOW-500',
            'purchase_date' => '2023-06-15',
            'purchase_value' => 45000.00,
            'location' => 'Bottling Hall',
            'status' => 'operational',
            'next_maintenance_due' => '2026-06-15',
            'is_active' => true,
            'notes' => 'Annual membrane replacement required',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('Crossflow Filter CF-500');
        expect($data['equipment_type'])->toBe('filter');
        expect($data['serial_number'])->toBe('CF-2023-500');
        expect($data['manufacturer'])->toBe('Pall Corporation');
        expect($data['model_number'])->toBe('OENOFLOW-500');
        expect($data['purchase_date'])->toBe('2023-06-15');
        expect($data['purchase_value'])->toEqual(45000);
        expect($data['location'])->toBe('Bottling Hall');
        expect($data['status'])->toBe('operational');
        expect($data['next_maintenance_due'])->toBe('2026-06-15');
        expect($data['is_active'])->toBeTrue();
        expect($data['is_maintenance_overdue'])->toBeFalse();
    });

    it('creates with minimal required fields', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-crud-min');

        $response = test()->postJson('/api/v1/equipment', [
            'name' => 'Basic Tank',
            'equipment_type' => 'tank',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('Basic Tank');
        expect($data['status'])->toBe('operational');
        expect($data['is_active'])->toBeTrue();
        expect($data['serial_number'])->toBeNull();
        expect($data['manufacturer'])->toBeNull();
        expect($data['purchase_date'])->toBeNull();
        expect($data['purchase_value'])->toBeNull();
        expect($data['next_maintenance_due'])->toBeNull();
    });

    it('lists equipment with pagination', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-crud-list');

        $tenant->run(function () {
            Equipment::create(['name' => 'Tank A', 'equipment_type' => 'tank', 'status' => 'operational']);
            Equipment::create(['name' => 'Pump B', 'equipment_type' => 'pump', 'status' => 'operational']);
            Equipment::create(['name' => 'Press C', 'equipment_type' => 'press', 'status' => 'maintenance']);
        });

        $response = test()->getJson('/api/v1/equipment', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('meta.total'))->toBe(3);
    });

    it('filters by equipment_type', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-filter-type');

        $tenant->run(function () {
            Equipment::create(['name' => 'Tank A', 'equipment_type' => 'tank', 'status' => 'operational']);
            Equipment::create(['name' => 'Pump B', 'equipment_type' => 'pump', 'status' => 'operational']);
        });

        $response = test()->getJson('/api/v1/equipment?equipment_type=tank', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.equipment_type'))->toBe('tank');
    });

    it('filters by status', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-filter-status');

        $tenant->run(function () {
            Equipment::create(['name' => 'Running Tank', 'equipment_type' => 'tank', 'status' => 'operational']);
            Equipment::create(['name' => 'Down Pump', 'equipment_type' => 'pump', 'status' => 'maintenance']);
        });

        $response = test()->getJson('/api/v1/equipment?status=maintenance', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Down Pump');
    });

    it('filters maintenance overdue', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-filter-overdue');

        $tenant->run(function () {
            Equipment::create(['name' => 'Overdue', 'equipment_type' => 'tank', 'status' => 'operational', 'next_maintenance_due' => now()->subDays(5)]);
            Equipment::create(['name' => 'OK', 'equipment_type' => 'pump', 'status' => 'operational', 'next_maintenance_due' => now()->addDays(30)]);
        });

        $response = test()->getJson('/api/v1/equipment?maintenance_overdue=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Overdue');
    });

    it('shows equipment with maintenance logs', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-show');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Show Test Filter',
                'equipment_type' => 'filter',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;

            MaintenanceLog::create([
                'equipment_id' => $item->id,
                'maintenance_type' => 'cleaning',
                'performed_date' => '2026-03-10',
                'description' => 'Standard wash',
            ]);
        });

        $response = test()->getJson("/api/v1/equipment/{$equipmentId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.id'))->toBe($equipmentId);
        expect($response->json('data.name'))->toBe('Show Test Filter');
        expect($response->json('data.maintenance_logs'))->toHaveCount(1);
        expect($response->json('data.maintenance_logs.0.maintenance_type'))->toBe('cleaning');
    });

    it('updates an existing equipment item', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-update');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = Equipment::create([
                'name' => 'Old Pump',
                'equipment_type' => 'pump',
                'status' => 'operational',
                'manufacturer' => 'Waukesha',
            ]);
            $itemId = $item->id;
        });

        $response = test()->putJson("/api/v1/equipment/{$itemId}", [
            'name' => 'Refurbished Pump',
            'status' => 'maintenance',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Refurbished Pump');
        expect($response->json('data.status'))->toBe('maintenance');
        expect($response->json('data.manufacturer'))->toBe('Waukesha');
        expect($response->json('data.equipment_type'))->toBe('pump');
    });
})->group('inventory');

// ─── Tier 2: Maintenance Log CRUD ────────────────────────────────

describe('maintenance log CRUD', function () {
    it('creates a maintenance log with calibration pass/fail', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-maint-cal');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Refractometer',
                'equipment_type' => 'lab_instrument',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        $response = test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'calibration',
            'performed_date' => '2026-03-15',
            'description' => 'Brix calibration with distilled water',
            'findings' => 'Reading within 0.1% tolerance',
            'passed' => true,
            'cost' => 0,
            'next_due_date' => '2026-06-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['maintenance_type'])->toBe('calibration');
        expect($data['performed_date'])->toBe('2026-03-15');
        expect($data['passed'])->toBeTrue();
        expect($data['next_due_date'])->toBe('2026-06-15');
    });

    it('creates a CIP log entry', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-maint-cip');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Fermentation Tank #3',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        $response = test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'cip',
            'performed_date' => '2026-03-14',
            'description' => 'CIP cycle: caustic 2%, hot water rinse, citric 1%, final rinse',
            'notes' => 'Pre-harvest preparation',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        expect($response->json('data.maintenance_type'))->toBe('cip');
    });

    it('lists maintenance logs for equipment', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-maint-list');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Test Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;

            MaintenanceLog::create(['equipment_id' => $item->id, 'maintenance_type' => 'cleaning', 'performed_date' => '2026-03-01']);
            MaintenanceLog::create(['equipment_id' => $item->id, 'maintenance_type' => 'cip', 'performed_date' => '2026-03-10']);
            MaintenanceLog::create(['equipment_id' => $item->id, 'maintenance_type' => 'inspection', 'performed_date' => '2026-03-15']);
        });

        $response = test()->getJson("/api/v1/equipment/{$equipmentId}/maintenance-logs", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('meta.total'))->toBe(3);
    });

    it('filters maintenance logs by type', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-maint-filter');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Filter Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;

            MaintenanceLog::create(['equipment_id' => $item->id, 'maintenance_type' => 'cip', 'performed_date' => '2026-03-01']);
            MaintenanceLog::create(['equipment_id' => $item->id, 'maintenance_type' => 'cleaning', 'performed_date' => '2026-03-05']);
        });

        $response = test()->getJson("/api/v1/equipment/{$equipmentId}/maintenance-logs?maintenance_type=cip", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.maintenance_type'))->toBe('cip');
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('equipment validation', function () {
    it('rejects missing required equipment fields', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-val-missing');

        $response = test()->postJson('/api/v1/equipment', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('name');
        expect($fields)->toContain('equipment_type');
    });

    it('rejects invalid equipment_type', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-val-type');

        $response = test()->postJson('/api/v1/equipment', [
            'name' => 'Bad Type',
            'equipment_type' => 'invalid_type',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('equipment_type');
    });

    it('rejects invalid status', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-val-status');

        $response = test()->postJson('/api/v1/equipment', [
            'name' => 'Bad Status',
            'equipment_type' => 'tank',
            'status' => 'broken',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });

    it('rejects missing required maintenance log fields', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-val-maint');

        $response = test()->postJson('/api/v1/maintenance-logs', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('equipment_id');
        expect($fields)->toContain('maintenance_type');
        expect($fields)->toContain('performed_date');
    });

    it('rejects invalid maintenance_type', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-val-mtype');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Val Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        $response = test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'invalid_type',
            'performed_date' => '2026-03-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('equipment RBAC', function () {
    it('admin can create equipment', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-rbac-admin', 'admin');

        test()->postJson('/api/v1/equipment', [
            'name' => 'Admin Tank',
            'equipment_type' => 'tank',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('winemaker cannot create equipment', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-rbac-wm', 'winemaker');

        test()->postJson('/api/v1/equipment', [
            'name' => 'WM Tank',
            'equipment_type' => 'tank',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('winemaker can create maintenance logs', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-rbac-wm-maint', 'winemaker');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'WM Maint Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'cleaning',
            'performed_date' => '2026-03-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('cellar_hand cannot create maintenance logs', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-rbac-ch', 'cellar_hand');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'CH Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        test()->postJson('/api/v1/maintenance-logs', [
            'equipment_id' => $equipmentId,
            'maintenance_type' => 'cleaning',
            'performed_date' => '2026-03-15',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('any authenticated user can list and view equipment', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-rbac-ro-view', 'read_only');

        $equipmentId = null;
        $tenant->run(function () use (&$equipmentId) {
            $item = Equipment::create([
                'name' => 'Viewable Tank',
                'equipment_type' => 'tank',
                'status' => 'operational',
            ]);
            $equipmentId = $item->id;
        });

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ];

        test()->getJson('/api/v1/equipment', $headers)->assertOk();
        test()->getJson("/api/v1/equipment/{$equipmentId}", $headers)->assertOk();
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('equipment API envelope', function () {
    it('returns responses in the standard API envelope format', function () {
        [$tenant, $token] = createEquipmentTestTenant('eq-envelope');

        $response = test()->postJson('/api/v1/equipment', [
            'name' => 'Envelope Test',
            'equipment_type' => 'tank',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'equipment_type', 'status', 'is_maintenance_overdue', 'is_active'],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);
    });

    it('rejects unauthenticated access', function () {
        test()->getJson('/api/v1/equipment', [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');
