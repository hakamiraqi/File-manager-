<?php

namespace App\Mail;

use App\Models\Share;
use App\Models\ShareEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShareNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Share $share,
        public readonly ShareEmail $shareEmail,
    ) {}

    public function envelope(): Envelope
    {
        $senderName = $this->share->user?->name ?? config('app.name');

        return new Envelope(
            subject: "{$senderName} شارك معك ملفاً عبر ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.share-notification',
            with: [
                'senderName' => $this->share->user?->name,
                'itemName' => $this->share->shareable?->name,
                'message' => $this->shareEmail->message,
                'url' => route('share.public.show', $this->share->token),
                'expiresAt' => $this->share->expires_at,
            ],
        );
    }
}
