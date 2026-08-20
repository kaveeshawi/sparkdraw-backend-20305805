<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Client $client,
        public string $inviteUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to your client portal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-invite',
        );
    }
}
