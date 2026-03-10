<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Routes here are loaded by TenancyServiceProvider and have the
| InitializeTenancyByDomain middleware applied. They execute in
| the tenant's context (tenant database schema, cache prefix, etc.).
|
| For API routes that use token-based tenant identification (mobile apps),
| see routes/api.php with InitializeTenancyByRequestData middleware.
|
*/

Route::get('/', function () {
    return response()->json([
        'tenant' => tenant('id'),
        'name' => tenant('name'),
    ]);
});
