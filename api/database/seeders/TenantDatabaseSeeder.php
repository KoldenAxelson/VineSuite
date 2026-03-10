<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds default data into a newly-created tenant schema.
 * Called automatically by stancl/tenancy after schema creation and migration.
 *
 * Will be expanded in Sub-Task 4 (RBAC) to seed default roles and permissions,
 * and in Sub-Task 7 (Winery Profile) to seed default winery profile settings.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sub-Task 4 will add: $this->call(RolesAndPermissionsSeeder::class);
        // Sub-Task 7 will add: WineryProfile defaults
    }
}
