<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AgentInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $agentName,
        public readonly string $acceptUrl,
        public readonly string $expiresAt,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Set up your '.config('app.name').' agent account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.agent-invitation',
            with: [
                'agentName' => $this->agentName,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
