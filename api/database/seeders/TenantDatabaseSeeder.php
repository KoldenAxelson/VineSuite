<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds default data into a newly-created tenant schema.
 * Called automatically by stancl/tenancy after schema creation and migration.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        // Sub-Task 7 will add: WineryProfile defaults
    }
}
