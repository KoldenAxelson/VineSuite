<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogoutController extends Controller
{
    /**
     * Revoke the current token (logout from this device).
     */
    public function __invoke(Request $request): JsonResponse
    {
        Log::info('User logged out', [
            'user_id' => $request->user()->id,
            'tenant_id' => tenant('id'),
        ]);

        $request->user()->currentAccessToken()->delete();

        return ApiResponse::message('Logged out.');
    }
}
