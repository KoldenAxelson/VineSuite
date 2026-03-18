<?php

declare(strict_types=1);
use App\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;

return [
    'tenant_model' => Tenant::class,

    /**
     * Features enabled for tenancy.
     * See https://tenancyforlaravel.com/docs/v3/features
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
    ],

    /**
     * Central domains that should NOT be treated as tenant domains.
     * The management portal and API live here.
     */
    'central_domains' => explode(',', env('TENANCY_CENTRAL_DOMAINS', 'localhost')),

    /**
     * Tenant identification.
     *
     * We use TWO identification strategies:
     * 1. Subdomain — for web portal access (winery.vinesuite.com)
     * 2. API token header — for mobile app access (X-Tenant-ID header)
     *
     * The middleware is configured in TenancyServiceProvider.
     */
    'identification' => [
        /**
         * InitializeTenancyByRequestData looks for tenant ID in these locations.
         * Used by mobile apps and API consumers to identify the tenant.
         */
        'header' => 'X-Tenant-ID',
    ],

    /**
     * Storage driver for tenant databases.
     * We use PostgreSQL schemas (not separate databases).
     */
    'database' => [
        /**
         * The connection used for central (non-tenant) data.
         * This is the default Laravel DB connection.
         */
        'central_connection' => env('DB_CONNECTION', 'pgsql'),

        /**
         * Connection used as a template for creating tenant database connections.
         * null means use the central_connection as the template.
         */
        'template_tenant_connection' => null,

        'prefix' => 'tenant_',
        'suffix' => '',

        /**
         * TenantDatabaseManagers are classes that handle creating and deleting
         * tenant databases. We use the PostgreSQL schema manager.
         */
        'managers' => [
            'pgsql' => PostgreSQLSchemaManager::class,
        ],
    ],

    /**
     * Cache configuration for tenancy.
     */
    'cache' => [
        'tag_base' => 'tenant_',
    ],

    /**
     * Filesystem configuration for tenancy.
     */
    'filesystem' => [
        'suffix_base' => 'tenant_',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,

        // Disable tenancy's asset() URL rewriting so Filament's CSS/JS
        // are served from /css/filament/ and /js/filament/ as normal,
        // instead of being routed through /tenancy/assets/...
        'asset_helper_tenancy' => false,
    ],

    /**
     * Redis tenancy configuration.
     * Prefix tenant Redis keys to prevent cross-tenant cache pollution.
     */
    'redis' => [
        'prefix_base' => 'tenant_',
        'prefixed_connections' => [
            'default',
            'cache',
        ],
    ],

    /**
     * Bootstrappers are executed when tenancy is initialized.
     * They switch the app context to the tenant's scope.
     */
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        // Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Enable when using Redis prefixing
    ],

    /**
     * Migration parameters for tenant migrations.
     * Tenant migrations live in a separate directory from central migrations.
     */
    'migration_parameters' => [
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    /**
     * Seeder parameters for tenant seeding.
     */
    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\TenantDatabaseSeeder',
    ],
];
