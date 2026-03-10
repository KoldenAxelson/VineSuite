<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email sent to an invitee with their unique acceptance link.
 * Contains the invitation token and the winery name.
 */
class TeamInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly TeamInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        $tenantName = tenant('name') ?? 'VineSuite';

        return new Envelope(
            subject: "You've been invited to join {$tenantName} on VineSuite",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.team-invitation',
            with: [
                'acceptUrl' => $this->buildAcceptUrl(),
                'tenantName' => tenant('name') ?? 'VineSuite',
                'role' => str_replace('_', ' ', ucfirst($this->invitation->role)),
                'expiresAt' => $this->invitation->expires_at->format('F j, Y g:i A'),
                'inviterName' => $this->invitation->inviter !== null ? $this->invitation->inviter->name : 'A team member',
            ],
        );
    }

    /**
     * Build the accept URL with the invitation token.
     * Points to the frontend accept page; the frontend calls the API.
     */
    protected function buildAcceptUrl(): string
    {
        $baseUrl = config('app.frontend_url', config('app.url'));

        return "{$baseUrl}/invitations/accept?token={$this->invitation->token}";
    }
}
