<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AdditionController;
use App\Http\Controllers\Api\V1\BarrelController;
use App\Http\Controllers\Api\V1\EventSyncController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\VesselController;
use App\Http\Controllers\Api\V1\WineryProfileController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\WebhookController;
use App\Http\Responses\ApiResponse;
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
    return ApiResponse::success([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Stripe webhook — central, no tenant context, no CSRF
Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])->name('cashier.webhook');

// ─── Tenant-Scoped API Routes ───────────────────────────────────
// These use InitializeTenancyByRequestData to identify the tenant
// via X-Tenant-ID header (for mobile apps and API consumers).
// Rate limiting is applied per token type (portal/mobile/widget).
Route::middleware([
    InitializeTenancyByRequestData::class,
    'throttle.token',
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

            return ApiResponse::success([
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

        // ─── Production: Lots ─────────────────────────────────────
        Route::get('/lots', [LotController::class, 'index'])->name('lots.index');
        Route::get('/lots/{lot}', [LotController::class, 'show'])->name('lots.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/lots', [LotController::class, 'store'])->name('lots.store');
            Route::put('/lots/{lot}', [LotController::class, 'update'])->name('lots.update');
        });

        // ─── Production: Vessels ─────────────────────────────────
        Route::get('/vessels', [VesselController::class, 'index'])->name('vessels.index');
        Route::get('/vessels/{vessel}', [VesselController::class, 'show'])->name('vessels.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/vessels', [VesselController::class, 'store'])->name('vessels.store');
            Route::put('/vessels/{vessel}', [VesselController::class, 'update'])->name('vessels.update');
        });

        // ─── Production: Barrels ────────────────────────────────
        Route::get('/barrels', [BarrelController::class, 'index'])->name('barrels.index');
        Route::get('/barrels/{barrel}', [BarrelController::class, 'show'])->name('barrels.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/barrels', [BarrelController::class, 'store'])->name('barrels.store');
            Route::put('/barrels/{barrel}', [BarrelController::class, 'update'])->name('barrels.update');
        });

        // ─── Production: Work Orders ────────────────────────────
        Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
        Route::get('/work-orders/calendar', [WorkOrderController::class, 'calendar'])->name('work-orders.calendar');
        Route::get('/work-orders/templates', [WorkOrderController::class, 'templates'])->name('work-orders.templates');
        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
            Route::post('/work-orders/bulk', [WorkOrderController::class, 'bulkStore'])->name('work-orders.bulk');
        });

        // Update/complete — cellar_hand+ (spec says cellar_hand+ for update)
        Route::middleware('role:owner,admin,winemaker,cellar_hand')->group(function () {
            Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
            Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');
        });

        // ─── Production: Additions ────────────────────────────────
        Route::get('/additions', [AdditionController::class, 'index'])->name('additions.index');
        Route::get('/additions/so2-total', [AdditionController::class, 'so2Total'])->name('additions.so2-total');
        Route::get('/additions/{addition}', [AdditionController::class, 'show'])->name('additions.show');

        Route::middleware('role:owner,admin,winemaker,cellar_hand')->group(function () {
            Route::post('/additions', [AdditionController::class, 'store'])->name('additions.store');
        });

        // ─── Production: Transfers ────────────────────────────────
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');

        Route::middleware('role:owner,admin,winemaker,cellar_hand')->group(function () {
            Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        });

        // Team list — any authenticated user can view team members
        Route::get('/team', function () {
            $users = \App\Models\User::select('id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'created_at')
                ->orderBy('name')
                ->get();

            return ApiResponse::success($users);
        })->name('team.index');
    });
});
