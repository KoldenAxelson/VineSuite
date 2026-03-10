<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Authenticate a user and return a Sanctum token.
     *
     * Tokens are scoped by client type (portal, cellar_app, pos_app, widget, public_api).
     * Each client type gets different token abilities.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'client_type' => ['required', 'string', 'in:portal,cellar_app,pos_app,widget,public_api'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        // Get abilities for this client type
        $abilities = User::TOKEN_ABILITIES[$validated['client_type']] ?? [];

        // Create the token with client-scoped abilities
        $token = $user->createToken(
            name: $validated['device_name'],
            abilities: $abilities,
        );

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        Log::info('User logged in', [
            'user_id' => $user->id,
            'tenant_id' => tenant('id'),
            'client_type' => $validated['client_type'],
        ]);

        return response()->json([
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
