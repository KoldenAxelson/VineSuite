<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

/**
 * Handles accepting a team invitation.
 *
 * The invitee provides the invitation token along with their name and password.
 * A new user account is created with the role specified in the invitation.
 * This endpoint does NOT require authentication (the invitee doesn't have an account yet).
 */
class AcceptInvitationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $invitation = TeamInvitation::where('token', $validated['token'])->first();

        if (! $invitation) {
            return response()->json([
                'message' => 'Invalid invitation token.',
            ], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json([
                'message' => 'This invitation has already been accepted.',
            ], 422);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'message' => 'This invitation has expired. Please ask the team admin to send a new one.',
            ], 422);
        }

        // Check if user with this email already exists
        if (User::where('email', $invitation->email)->exists()) {
            return response()->json([
                'message' => 'A user with this email already exists.',
            ], 422);
        }

        // Create the user with the invited role
        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => $validated['password'],
            'role' => $invitation->role,
            'is_active' => true,
            'invited_by' => $invitation->invited_by,
        ]);

        // Assign the spatie role
        $user->assignRole($invitation->role);

        // Mark invitation as accepted
        $invitation->update([
            'accepted_at' => now(),
        ]);

        // Create a portal token for immediate use
        $token = $user->createToken(
            name: 'portal',
            abilities: User::TOKEN_ABILITIES['portal'],
        );

        Log::info('Team invitation accepted', [
            'invitation_id' => $invitation->id,
            'user_id' => $user->id,
            'role' => $user->role,
            'tenant_id' => tenant('id'),
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 201);
    }
}
