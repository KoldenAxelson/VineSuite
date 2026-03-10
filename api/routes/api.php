<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Api\V1\EventSyncController;
use App\Http\Controllers\Api\V1\WineryProfileController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefixed with /api/v1/ (configured in bootstrap/app.php).
|
| Central routes (no tenant context): health check, etc.
| Tenant routes: auth, resources — require tenant identification.
|
*/

// ─── Central (no tenant context) ────────────────────────────────
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Stripe webhook — central, no tenant context, no CSRF
Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])->name('cashier.webhook');

// ─── Tenant-Scoped API Routes ───────────────────────────────────
// These use InitializeTenancyByRequestData to identify the tenant
// via X-Tenant-ID header (for mobile apps and API consumers).
Route::middleware([
    InitializeTenancyByRequestData::class,
])->group(function () {

    // Auth — public (no token required)
    Route::prefix('auth')->group(function () {
        Route::post('/register', RegisterController::class)->name('auth.register');
        Route::post('/login', LoginController::class)->name('auth.login');
        Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->name('auth.forgot-password');
        Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->name('auth.reset-password');
        Route::post('/accept-invitation', AcceptInvitationController::class)->name('auth.accept-invitation');
    });

    // Auth — requires valid Sanctum token
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', LogoutController::class)->name('auth.logout');

        Route::get('/auth/me', function () {
            $user = request()->user();
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]);
        })->name('auth.me');

        // Team management — requires owner or admin role
        Route::middleware('role:owner,admin')->prefix('team')->group(function () {
            Route::post('/invite', [TeamInvitationController::class, 'send'])->name('team.invite');
            Route::get('/invitations', [TeamInvitationController::class, 'index'])->name('team.invitations');
            Route::delete('/invitations/{invitation}', [TeamInvitationController::class, 'cancel'])->name('team.invitations.cancel');
        });

        // Winery profile — any authenticated user can view
        Route::get('/winery', [WineryProfileController::class, 'show'])->name('winery.show');

        // Winery profile update — owner/admin only
        Route::put('/winery', [WineryProfileController::class, 'update'])
            ->middleware('role:owner,admin')
            ->name('winery.update');

        // Event sync — mobile apps batch-submit events
        Route::post('/events/sync', EventSyncController::class)->name('events.sync');

        // Billing — owner/admin only
        Route::middleware('role:owner,admin')->prefix('billing')->group(function () {
            Route::get('/status', [BillingController::class, 'status'])->name('billing.status');
            Route::post('/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
            Route::post('/portal', [BillingController::class, 'portal'])->name('billing.portal');
            Route::put('/plan', [BillingController::class, 'changePlan'])->name('billing.plan');
        });

        // Team list — any authenticated user can view team members
        Route::get('/team', function () {
            $users = \App\Models\User::select('id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'created_at')
                ->orderBy('name')
                ->get();

            return response()->json(['data' => $users]);
        })->name('team.index');
    });
});
