<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

/**
 * Handles owner registration during tenant onboarding.
 *
 * Regular team members are added via the invitation system (Sub-Task 5),
 * not through this registration endpoint. This endpoint creates the
 * first user (Owner) for a new tenant.
 */
class RegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            ...$validated,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Assign the Owner spatie role
        $user->assignRole('owner');

        // Fire Laravel's Registered event (triggers email verification)
        event(new Registered($user));

        // Create a portal token for immediate use
        $token = $user->createToken(
            name: 'portal|registration',
            abilities: User::TOKEN_ABILITIES['portal'],
        );

        Log::info('Owner registered', [
            'user_id' => $user->id,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::created([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
