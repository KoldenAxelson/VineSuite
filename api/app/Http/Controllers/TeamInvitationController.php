<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Manages team invitations: send, list, cancel.
 *
 * Only Owner and Admin roles can send/cancel invitations.
 * Invitations expire after 72 hours. Duplicate pending invitations
 * to the same email are blocked.
 */
class TeamInvitationController extends Controller
{
    /**
     * Send a team invitation.
     *
     * POST /api/v1/team/invite
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => [
                'required',
                'string',
                Rule::in(['admin', 'winemaker', 'cellar_hand', 'tasting_room_staff', 'accountant', 'read_only']),
            ],
        ]);

        // Block if a pending invitation already exists for this email
        $existing = TeamInvitation::pending()
            ->where('email', $validated['email'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A pending invitation already exists for this email address.',
            ], 422);
        }

        // Block if a user with this email already exists in this tenant
        if (\App\Models\User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'A user with this email already exists in this winery.',
            ], 422);
        }

        $invitation = TeamInvitation::create([
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token' => Str::random(64),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours(72),
        ]);

        Mail::to($validated['email'])->send(new TeamInvitationMail($invitation));

        Log::info('Team invitation sent', [
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'invited_by' => $request->user()->id,
            'tenant_id' => tenant('id'),
        ]);

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * List all invitations (pending and accepted).
     *
     * GET /api/v1/team/invitations
     */
    public function index(): JsonResponse
    {
        $invitations = TeamInvitation::orderByDesc('created_at')->get();

        return response()->json([
            'data' => $invitations->map(fn (TeamInvitation $inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'role' => $inv->role,
                'invited_by' => $inv->invited_by,
                'accepted_at' => $inv->accepted_at?->toIso8601String(),
                'expires_at' => $inv->expires_at->toIso8601String(),
                'status' => $inv->isAccepted() ? 'accepted' : ($inv->isExpired() ? 'expired' : 'pending'),
            ]),
        ]);
    }

    /**
     * Cancel a pending invitation.
     *
     * DELETE /api/v1/team/invitations/{invitation}
     */
    public function cancel(TeamInvitation $invitation): JsonResponse
    {
        if ($invitation->isAccepted()) {
            return response()->json([
                'message' => 'This invitation has already been accepted and cannot be cancelled.',
            ], 422);
        }

        Log::info('Team invitation cancelled', [
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'cancelled_by' => request()->user()->id,
            'tenant_id' => tenant('id'),
        ]);

        $invitation->delete();

        return response()->json([
            'message' => 'Invitation cancelled successfully.',
        ]);
    }
}
