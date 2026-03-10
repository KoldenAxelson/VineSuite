<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Creates a new tenant with full provisioning:
 * 1. Creates the Tenant model (triggers schema creation + migration + seeding via events)
 * 2. Creates the subdomain domain record
 * 3. Logs the result
 *
 * Should complete in under 10 seconds (acceptance criteria).
 */
class CreateTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $plan = 'starter',
        public readonly ?string $ownerEmail = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): Tenant
    {
        $startTime = microtime(true);

        Log::info('Tenant creation started', [
            'slug' => $this->slug,
            'plan' => $this->plan,
        ]);

        // Step 1: Create the tenant
        // This triggers TenancyServiceProvider events:
        //   CreateDatabase → MigrateDatabase → SeedDatabase
        $tenant = Tenant::create([
            'name' => $this->name,
            'slug' => $this->slug,
            'plan' => $this->plan,
        ]);

        // Step 2: Create the subdomain domain record
        $tenant->domains()->create([
            'domain' => $this->slug,
        ]);

        $elapsed = round((microtime(true) - $startTime) * 1000);

        Log::info('Tenant creation completed', [
            'tenant_id' => $tenant->id,
            'slug' => $this->slug,
            'elapsed_ms' => $elapsed,
        ]);

        return $tenant;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Tenant creation failed', [
            'slug' => $this->slug,
            'error' => $exception->getMessage(),
        ]);
    }
}
