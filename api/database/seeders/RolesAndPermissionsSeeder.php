<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 7 roles and their permissions for a tenant.
 *
 * Permission naming convention: {resource}.{action}
 * e.g., lots.create, lots.read, lots.update, lots.delete
 *
 * This seeder runs automatically when a new tenant is created
 * (called by TenantDatabaseSeeder).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ─── Define all permissions ─────────────────────────────────────
        $permissions = [
            // Production / Cellar
            'lots.create', 'lots.read', 'lots.update', 'lots.delete',
            'vessels.create', 'vessels.read', 'vessels.update', 'vessels.delete',
            'work-orders.create', 'work-orders.read', 'work-orders.update', 'work-orders.delete',
            'additions.create', 'additions.read',
            'transfers.create', 'transfers.read',
            'barrels.create', 'barrels.read', 'barrels.update', 'barrels.delete',
            'lab.create', 'lab.read', 'lab.update',
            'fermentation.create', 'fermentation.read',
            'bottling.create', 'bottling.read',
            'blending.create', 'blending.read',

            // Vineyard
            'vineyard.create', 'vineyard.read', 'vineyard.update',

            // Inventory
            'inventory.create', 'inventory.read', 'inventory.update', 'inventory.adjust',

            // Sales & Orders
            'orders.create', 'orders.read', 'orders.update', 'orders.refund',
            'pos.operate',

            // Customers & CRM
            'customers.create', 'customers.read', 'customers.update', 'customers.delete',

            // Club
            'club.manage', 'club.read', 'club.process-charges',

            // Reservations & Events
            'reservations.create', 'reservations.read', 'reservations.update', 'reservations.delete',

            // Compliance
            'compliance.read', 'compliance.generate-reports',

            // Reporting
            'reports.read', 'reports.export',
            'cogs.read', 'cogs.update',

            // Settings & Admin
            'settings.read', 'settings.update',
            'users.create', 'users.read', 'users.update', 'users.delete',
            'billing.read', 'billing.update',
            'integrations.read', 'integrations.update',

            // Winery Profile
            'winery-profile.read', 'winery-profile.update',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // ─── Define roles with their permissions ────────────────────────

        // Owner — full access to everything
        Role::create(['name' => 'owner'])->givePermissionTo(Permission::all());

        // Admin — everything except billing
        Role::create(['name' => 'admin'])->givePermissionTo(
            Permission::whereNotIn('name', ['billing.read', 'billing.update'])->get()
        );

        // Winemaker — production, lab, compliance, reporting, vineyard
        Role::create(['name' => 'winemaker'])->givePermissionTo([
            'lots.create', 'lots.read', 'lots.update',
            'vessels.create', 'vessels.read', 'vessels.update',
            'work-orders.create', 'work-orders.read', 'work-orders.update', 'work-orders.delete',
            'additions.create', 'additions.read',
            'transfers.create', 'transfers.read',
            'barrels.create', 'barrels.read', 'barrels.update',
            'lab.create', 'lab.read', 'lab.update',
            'fermentation.create', 'fermentation.read',
            'bottling.create', 'bottling.read',
            'blending.create', 'blending.read',
            'vineyard.create', 'vineyard.read', 'vineyard.update',
            'inventory.read', 'inventory.update', 'inventory.adjust',
            'compliance.read', 'compliance.generate-reports',
            'reports.read', 'reports.export',
            'cogs.read',
            'winery-profile.read',
        ]);

        // Cellar Hand — work orders, additions, transfers, barrel ops (no admin)
        Role::create(['name' => 'cellar_hand'])->givePermissionTo([
            'lots.read',
            'vessels.read',
            'work-orders.read', 'work-orders.update',
            'additions.create', 'additions.read',
            'transfers.create', 'transfers.read',
            'barrels.read', 'barrels.update',
            'lab.create', 'lab.read',
            'fermentation.create', 'fermentation.read',
            'inventory.read',
            'winery-profile.read',
        ]);

        // Tasting Room Staff — POS, reservations, basic CRM
        Role::create(['name' => 'tasting_room_staff'])->givePermissionTo([
            'orders.create', 'orders.read', 'orders.update',
            'pos.operate',
            'customers.create', 'customers.read', 'customers.update',
            'club.read',
            'reservations.create', 'reservations.read', 'reservations.update',
            'inventory.read',
            'winery-profile.read',
        ]);

        // Accountant — reporting, COGS, integrations (read-heavy, no production ops)
        Role::create(['name' => 'accountant'])->givePermissionTo([
            'reports.read', 'reports.export',
            'cogs.read', 'cogs.update',
            'orders.read',
            'customers.read',
            'club.read',
            'compliance.read',
            'inventory.read',
            'integrations.read', 'integrations.update',
            'winery-profile.read',
        ]);

        // Read-Only — can view but not modify anything
        Role::create(['name' => 'read_only'])->givePermissionTo([
            'lots.read',
            'vessels.read',
            'work-orders.read',
            'additions.read',
            'transfers.read',
            'barrels.read',
            'lab.read',
            'fermentation.read',
            'bottling.read',
            'blending.read',
            'vineyard.read',
            'inventory.read',
            'orders.read',
            'customers.read',
            'club.read',
            'reservations.read',
            'compliance.read',
            'reports.read',
            'cogs.read',
            'winery-profile.read',
        ]);
    }
}
