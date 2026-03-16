<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AdditionController;
use App\Http\Controllers\Api\V1\BarrelController;
use App\Http\Controllers\Api\V1\BarrelOperationController;
use App\Http\Controllers\Api\V1\BlendController;
use App\Http\Controllers\Api\V1\BottlingRunController;
use App\Http\Controllers\Api\V1\CaseGoodsSkuController;
use App\Http\Controllers\Api\V1\EventSyncController;
use App\Http\Controllers\Api\V1\FermentationChartController;
use App\Http\Controllers\Api\V1\FermentationController;
use App\Http\Controllers\Api\V1\FilterLogController;
use App\Http\Controllers\Api\V1\LabAnalysisController;
use App\Http\Controllers\Api\V1\LabImportController;
use App\Http\Controllers\Api\V1\LabThresholdController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\LotSplitController;
use App\Http\Controllers\Api\V1\PhysicalCountController;
use App\Http\Controllers\Api\V1\PressLogController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\SensoryNoteController;
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
            Route::post('/lots/split', [LotSplitController::class, 'store'])->name('lots.split');
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

        // ─── Production: Press Logs ────────────────────────────────
        Route::get('/press-logs', [PressLogController::class, 'index'])->name('press-logs.index');
        Route::get('/press-logs/{pressLog}', [PressLogController::class, 'show'])->name('press-logs.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/press-logs', [PressLogController::class, 'store'])->name('press-logs.store');
        });

        // ─── Production: Filter Logs ──────────────────────────────
        Route::get('/filter-logs', [FilterLogController::class, 'index'])->name('filter-logs.index');
        Route::get('/filter-logs/{filterLog}', [FilterLogController::class, 'show'])->name('filter-logs.show');

        Route::middleware('role:owner,admin,winemaker,cellar_hand')->group(function () {
            Route::post('/filter-logs', [FilterLogController::class, 'store'])->name('filter-logs.store');
        });

        // ─── Production: Blend Trials ─────────────────────────────
        Route::get('/blend-trials', [BlendController::class, 'index'])->name('blend-trials.index');
        Route::get('/blend-trials/{blendTrial}', [BlendController::class, 'show'])->name('blend-trials.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/blend-trials', [BlendController::class, 'store'])->name('blend-trials.store');
            Route::post('/blend-trials/{blendTrial}/finalize', [BlendController::class, 'finalize'])->name('blend-trials.finalize');
        });

        // ─── Production: Barrel Operations ─────────────────────────
        Route::middleware('role:owner,admin,winemaker,cellar_hand')->prefix('barrel-operations')->group(function () {
            Route::post('/fill', [BarrelOperationController::class, 'fill'])->name('barrel-operations.fill');
            Route::post('/top', [BarrelOperationController::class, 'top'])->name('barrel-operations.top');
            Route::post('/rack', [BarrelOperationController::class, 'rack'])->name('barrel-operations.rack');
            Route::post('/sample', [BarrelOperationController::class, 'sample'])->name('barrel-operations.sample');
        });

        // ─── Production: Bottling Runs ──────────────────────────────
        Route::get('/bottling-runs', [BottlingRunController::class, 'index'])->name('bottling-runs.index');
        Route::get('/bottling-runs/{bottlingRun}', [BottlingRunController::class, 'show'])->name('bottling-runs.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/bottling-runs', [BottlingRunController::class, 'store'])->name('bottling-runs.store');
            Route::post('/bottling-runs/{bottlingRun}/complete', [BottlingRunController::class, 'complete'])->name('bottling-runs.complete');
        });

        // ─── Lab Analyses (per lot) ──────────────────────────────
        Route::get('/lots/{lotId}/analyses', [LabAnalysisController::class, 'index'])->name('lab-analyses.index');
        Route::get('/lots/{lotId}/analyses/{analysis}', [LabAnalysisController::class, 'show'])->name('lab-analyses.show');

        Route::middleware('role:owner,admin,winemaker,cellar_hand')->group(function () {
            Route::post('/lots/{lotId}/analyses', [LabAnalysisController::class, 'store'])->name('lab-analyses.store');
        });

        // ─── Lab Thresholds ─────────────────────────────────────────
        Route::get('/lab-thresholds', [LabThresholdController::class, 'index'])->name('lab-thresholds.index');
        Route::get('/lab-thresholds/{threshold}', [LabThresholdController::class, 'show'])->name('lab-thresholds.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/lab-thresholds', [LabThresholdController::class, 'store'])->name('lab-thresholds.store');
            Route::put('/lab-thresholds/{threshold}', [LabThresholdController::class, 'update'])->name('lab-thresholds.update');
            Route::delete('/lab-thresholds/{threshold}', [LabThresholdController::class, 'destroy'])->name('lab-thresholds.destroy');
        });

        // ─── Lab CSV Import ─────────────────────────────────────────
        Route::middleware('role:owner,admin,winemaker')->prefix('lab-import')->group(function () {
            Route::post('/preview', [LabImportController::class, 'preview'])->name('lab-import.preview');
            Route::post('/commit', [LabImportController::class, 'commit'])->name('lab-import.commit');
        });

        // ─── Fermentation Rounds (per lot) ──────────────────────────
        Route::get('/lots/{lotId}/fermentations', [FermentationController::class, 'index'])->name('fermentation-rounds.index');
        Route::get('/lots/{lotId}/fermentations/{fermentationRound}', [FermentationController::class, 'show'])->name('fermentation-rounds.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/lots/{lotId}/fermentations', [FermentationController::class, 'store'])->name('fermentation-rounds.store');
        });

        // ─── Fermentation Entries (per round) ────────────────────────
        Route::get('/fermentations/{roundId}/entries', [FermentationController::class, 'entries'])->name('fermentation-entries.index');

        Route::middleware('role:owner,admin,winemaker,cellar_hand')->group(function () {
            Route::post('/fermentations/{roundId}/entries', [FermentationController::class, 'addEntry'])->name('fermentation-entries.store');
            Route::post('/fermentations/{roundId}/complete', [FermentationController::class, 'complete'])->name('fermentation-rounds.complete');
            Route::post('/fermentations/{roundId}/stuck', [FermentationController::class, 'markStuck'])->name('fermentation-rounds.stuck');
        });

        // ─── Fermentation Chart Data ────────────────────────────────
        Route::get('/fermentations/{roundId}/chart', [FermentationChartController::class, 'show'])->name('fermentation-chart.show');
        Route::get('/lots/{lotId}/fermentation-chart', [FermentationChartController::class, 'lotOverview'])->name('fermentation-chart.lot-overview');

        // ─── Sensory/Tasting Notes (per lot) ─────────────────────────
        Route::get('/lots/{lotId}/sensory-notes', [SensoryNoteController::class, 'index'])->name('sensory-notes.index');
        Route::get('/lots/{lotId}/sensory-notes/{sensoryNote}', [SensoryNoteController::class, 'show'])->name('sensory-notes.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/lots/{lotId}/sensory-notes', [SensoryNoteController::class, 'store'])->name('sensory-notes.store');
        });

        // ─── Inventory: Case Goods SKUs ──────────────────────────────
        Route::get('/skus', [CaseGoodsSkuController::class, 'index'])->name('skus.index');
        Route::get('/skus/{sku}', [CaseGoodsSkuController::class, 'show'])->name('skus.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/skus', [CaseGoodsSkuController::class, 'store'])->name('skus.store');
            Route::put('/skus/{sku}', [CaseGoodsSkuController::class, 'update'])->name('skus.update');
        });

        // ─── Inventory: Locations ───────────────────────────────────────
        Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
        Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
            Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        });

        // ─── Inventory: Physical Counts ────────────────────────────────
        Route::get('/physical-counts', [PhysicalCountController::class, 'index'])->name('physical-counts.index');
        Route::get('/physical-counts/{physicalCount}', [PhysicalCountController::class, 'show'])->name('physical-counts.show');

        Route::middleware('role:owner,admin,winemaker')->group(function () {
            Route::post('/physical-counts/start', [PhysicalCountController::class, 'start'])->name('physical-counts.start');
            Route::post('/physical-counts/{physicalCount}/record', [PhysicalCountController::class, 'recordCounts'])->name('physical-counts.record');
            Route::post('/physical-counts/{physicalCount}/approve', [PhysicalCountController::class, 'approve'])->name('physical-counts.approve');
            Route::post('/physical-counts/{physicalCount}/cancel', [PhysicalCountController::class, 'cancel'])->name('physical-counts.cancel');
        });

        // ─── Inventory: Stock Transfers ─────────────────────────────────
        Route::post('/stock/transfer', [StockTransferController::class, 'store'])->name('stock-transfers.store');

        // Team list — any authenticated user can view team members
        Route::get('/team', function () {
            $users = \App\Models\User::select('id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'created_at')
                ->orderBy('name')
                ->get();

            return ApiResponse::success($users);
        })->name('team.index');
    });
});
