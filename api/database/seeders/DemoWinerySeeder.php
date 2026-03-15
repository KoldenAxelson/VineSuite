<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WineryProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Creates a demo winery tenant with realistic Paso Robles data.
 *
 * Idempotent: checks for existing 'paso-robles-cellars' slug before creating.
 * Run with: php artisan db:seed --class=DemoWinerySeeder
 */
class DemoWinerySeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent — skip if already exists
        if (Tenant::where('slug', 'paso-robles-cellars')->exists()) {
            $this->command?->info('Demo winery "Paso Robles Cellars" already exists. Skipping.');

            return;
        }

        $this->command?->info('Creating demo winery: Paso Robles Cellars...');

        // Create tenant (triggers schema + migrations + TenantDatabaseSeeder)
        $tenant = Tenant::create([
            'name' => 'Paso Robles Cellars',
            'slug' => 'paso-robles-cellars',
            'plan' => 'pro',
        ]);

        // Create domain for subdomain routing (full hostname for InitializeTenancyByDomain)
        $tenant->domains()->create([
            'domain' => 'paso-robles-cellars.localhost',
        ]);

        $tenant->run(function () {
            // Update the auto-created winery profile with realistic Paso Robles details
            $profile = WineryProfile::first();
            $profile->update([
                'name' => 'Paso Robles Cellars',
                'dba_name' => 'PRC Wines',
                'description' => 'A family-owned winery nestled in the rolling hills of Paso Robles, specializing in Rhône-style blends and single-vineyard Cabernet Sauvignon. Established in 2008, we farm 45 acres of estate vineyards across the Adelaida District.',
                'website' => 'https://pasoroblescellars.example.com',
                'phone' => '(805) 555-0142',
                'email' => 'wine@pasoroblescellars.example.com',
                'address_line_1' => '4825 Vineyard Drive',
                'city' => 'Paso Robles',
                'state' => 'CA',
                'zip' => '93446',
                'country' => 'US',
                'timezone' => 'America/Los_Angeles',
                'ttb_permit_number' => 'BWC-CA-19847',
                'ttb_registry_number' => 'CA-2008-BWN-3421',
                'state_license_number' => 'CA-ABC-47291',
                'unit_system' => 'imperial',
                'currency' => 'USD',
                'fiscal_year_start_month' => 7, // July — common for wineries (post-harvest)
                'date_format' => 'm/d/Y',
                'onboarding_complete' => true,
            ]);

            // Create demo users with each role
            $this->createDemoUsers();

            // Seed realistic production data (lots, vessels, barrels, work orders, etc.)
            $this->call(ProductionSeeder::class);
        });

        Log::info('DemoWinerySeeder: demo winery created', [
            'tenant_id' => $tenant->id,
            'slug' => 'paso-robles-cellars',
        ]);

        $this->command?->info('Demo winery created successfully with demo users.');
    }

    protected function createDemoUsers(): void
    {
        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@vine.com',
                'role' => 'owner',
            ],
            [
                'name' => 'Sarah Mitchell',
                'email' => 'sarah@pasoroblescellars.example.com',
                'role' => 'owner',
            ],
            [
                'name' => 'James Ortega',
                'email' => 'james@pasoroblescellars.example.com',
                'role' => 'admin',
            ],
            [
                'name' => 'Elena Rossi',
                'email' => 'elena@pasoroblescellars.example.com',
                'role' => 'winemaker',
            ],
            [
                'name' => 'Carlos Mendez',
                'email' => 'carlos@pasoroblescellars.example.com',
                'role' => 'cellar_hand',
            ],
            [
                'name' => 'Amy Chen',
                'email' => 'amy@pasoroblescellars.example.com',
                'role' => 'tasting_room_staff',
            ],
            [
                'name' => 'David Park',
                'email' => 'david@pasoroblescellars.example.com',
                'role' => 'accountant',
            ],
            [
                'name' => 'Viewer Account',
                'email' => 'viewer@pasoroblescellars.example.com',
                'role' => 'read_only',
            ],
        ];

        foreach ($users as $userData) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => 'password',
                'role' => $userData['role'],
                'is_active' => true,
            ]);

            $user->assignRole($userData['role']);
        }
    }
}
