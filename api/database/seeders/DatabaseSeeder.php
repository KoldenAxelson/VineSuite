<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates the demo winery tenant with realistic Paso Robles data.
     */
    public function run(): void
    {
        $this->call(DemoWinerySeeder::class);
    }
}
