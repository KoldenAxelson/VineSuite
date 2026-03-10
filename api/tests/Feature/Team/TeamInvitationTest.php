<?php

declare(strict_types=1);

use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant, run callback in context, return tenant.
 */
function createTestTenantForTeam(string $slug = 'test-winery', ?Closure $callback = null): Tenant
{
    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'starter',
    ]);

    if ($callback) {
        $tenant->run($callback);
    }

    return $tenant;
}

/*
 * Helper: create an owner user in the given tenant and return [user, token].
 */
function createOwnerUser(Tenant $tenant): array
{
    $user = null;
    $tenant->run(function () use (&$user) {
        $user = User::create([
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    // Login to get a token
    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$user, $loginResponse->json('token')];
}

afterEach(function () {
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $schemas = DB::select(
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"
    );
    foreach ($schemas as $schema) {
        DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
    }
});

// ─── Send Invitation ────────────────────────────────────────────

it('owner can send a team invitation', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $token] = createOwnerUser($tenant);

    $response = $this->postJson('/api/v1/team/invite', [
        'email' => 'newmember@example.com',
        'role' => 'winemaker',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('invitation.email', 'newmember@example.com')
        ->assertJsonPath('invitation.role', 'winemaker')
        ->assertJsonStructure([
            'message',
            'invitation' => ['id', 'email', 'role', 'expires_at'],
        ]);

    Mail::assertSent(TeamInvitationMail::class, function ($mail) {
        return $mail->hasTo('newmember@example.com');
    });
});

it('blocks duplicate pending invitations to the same email', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $token] = createOwnerUser($tenant);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // First invitation
    $this->postJson('/api/v1/team/invite', [
        'email' => 'newmember@example.com',
        'role' => 'winemaker',
    ], $headers)->assertStatus(201);

    // Duplicate — should be blocked
    $response = $this->postJson('/api/v1/team/invite', [
        'email' => 'newmember@example.com',
        'role' => 'cellar_hand',
    ], $headers);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'A pending invitation already exists for this email address.']);
});

it('blocks invitation if user already exists in tenant', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam('test-winery', function () {
        $user = User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');
    });

    [$owner, $token] = createOwnerUser($tenant);

    $response = $this->postJson('/api/v1/team/invite', [
        'email' => 'existing@example.com',
        'role' => 'cellar_hand',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'A user with this email already exists in this winery.']);
});

it('rejects owner role in invitation', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $token] = createOwnerUser($tenant);

    $response = $this->postJson('/api/v1/team/invite', [
        'email' => 'newowner@example.com',
        'role' => 'owner',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('role');
});

it('non-admin users cannot send invitations', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam('test-winery', function () {
        $user = User::create([
            'name' => 'Winemaker User',
            'email' => 'winemaker@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');
    });

    // Login as winemaker
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'winemaker@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token = $loginResponse->json('token');

    $response = $this->postJson('/api/v1/team/invite', [
        'email' => 'newmember@example.com',
        'role' => 'cellar_hand',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

// ─── Accept Invitation ──────────────────────────────────────────

it('invitee can accept a valid invitation', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $ownerToken] = createOwnerUser($tenant);

    // Send an invitation
    $inviteResponse = $this->postJson('/api/v1/team/invite', [
        'email' => 'newmember@example.com',
        'role' => 'cellar_hand',
    ], [
        'Authorization' => "Bearer {$ownerToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Get the token from the invitation
    $invitationId = $inviteResponse->json('invitation.id');

    // Retrieve the invitation token from the DB
    $invitationToken = null;
    $tenant->run(function () use ($invitationId, &$invitationToken) {
        $invitationToken = TeamInvitation::find($invitationId)->token;
    });

    // Accept the invitation
    $acceptResponse = $this->postJson('/api/v1/auth/accept-invitation', [
        'token' => $invitationToken,
        'name' => 'New Member',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $acceptResponse->assertStatus(201)
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.email', 'newmember@example.com')
        ->assertJsonPath('user.role', 'cellar_hand');

    // Verify user exists with correct role
    $tenant->run(function () {
        $user = User::where('email', 'newmember@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->role)->toBe('cellar_hand');
        expect($user->is_active)->toBeTrue();
        expect($user->hasRole('cellar_hand'))->toBeTrue();
    });
});

it('rejects expired invitation', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $ownerToken] = createOwnerUser($tenant);

    // Create an invitation directly with an expired timestamp
    $token = Str::random(64);
    $tenant->run(function () use ($owner, $token) {
        TeamInvitation::create([
            'email' => 'expired@example.com',
            'role' => 'winemaker',
            'token' => $token,
            'invited_by' => User::where('email', 'owner@example.com')->first()->id,
            'expires_at' => now()->subHours(1),
        ]);
    });

    $response = $this->postJson('/api/v1/auth/accept-invitation', [
        'token' => $token,
        'name' => 'Late Member',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'This invitation has expired. Please ask the team admin to send a new one.']);
});

it('rejects already-accepted invitation', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $ownerToken] = createOwnerUser($tenant);

    // Send invitation
    $inviteResponse = $this->postJson('/api/v1/team/invite', [
        'email' => 'newmember@example.com',
        'role' => 'cellar_hand',
    ], [
        'Authorization' => "Bearer {$ownerToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $invitationId = $inviteResponse->json('invitation.id');

    $invitationToken = null;
    $tenant->run(function () use ($invitationId, &$invitationToken) {
        $invitationToken = TeamInvitation::find($invitationId)->token;
    });

    // Accept once
    $this->postJson('/api/v1/auth/accept-invitation', [
        'token' => $invitationToken,
        'name' => 'New Member',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    // Try to accept again
    $response = $this->postJson('/api/v1/auth/accept-invitation', [
        'token' => $invitationToken,
        'name' => 'Another Name',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'This invitation has already been accepted.']);
});

it('rejects invalid invitation token', function () {
    $tenant = createTestTenantForTeam();

    $response = $this->postJson('/api/v1/auth/accept-invitation', [
        'token' => Str::random(64),
        'name' => 'Bad Token User',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(404)
        ->assertJsonFragment(['message' => 'Invalid invitation token.']);
});

// ─── Cancel Invitation ──────────────────────────────────────────

it('owner can cancel a pending invitation', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $token] = createOwnerUser($tenant);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Send invitation
    $inviteResponse = $this->postJson('/api/v1/team/invite', [
        'email' => 'cancel-me@example.com',
        'role' => 'winemaker',
    ], $headers);

    $invitationId = $inviteResponse->json('invitation.id');

    // Cancel it
    $cancelResponse = $this->deleteJson("/api/v1/team/invitations/{$invitationId}", [], $headers);

    $cancelResponse->assertOk()
        ->assertJsonFragment(['message' => 'Invitation cancelled successfully.']);

    // Verify it's gone
    $tenant->run(function () use ($invitationId) {
        expect(TeamInvitation::find($invitationId))->toBeNull();
    });
});

// ─── List Invitations ───────────────────────────────────────────

it('lists all invitations with correct status', function () {
    Mail::fake();

    $tenant = createTestTenantForTeam();
    [$owner, $token] = createOwnerUser($tenant);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Send two invitations
    $this->postJson('/api/v1/team/invite', [
        'email' => 'pending@example.com',
        'role' => 'winemaker',
    ], $headers)->assertStatus(201);

    $this->postJson('/api/v1/team/invite', [
        'email' => 'another@example.com',
        'role' => 'cellar_hand',
    ], $headers)->assertStatus(201);

    // List them
    $response = $this->getJson('/api/v1/team/invitations', $headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data');

    $data = $response->json('data');
    expect($data[0]['status'])->toBe('pending');
    expect($data[1]['status'])->toBe('pending');
});
