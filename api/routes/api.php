<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Routes registered here are prefixed with /api/v1/ (configured in
| bootstrap/app.php). Central (non-tenant) API routes live at the top.
| Tenant-scoped routes should use the tenant identification middleware.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});
