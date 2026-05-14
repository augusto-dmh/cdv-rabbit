<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class DailyTokenSpendApproaching extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly int $consumed,
        public readonly int $cap,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[cdv-rabbit] Daily token spend approaching limit — {$this->workspace->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.daily-token-spend-approaching',
        );
    }

    /** @return array<int, mixed> */
    public function attachments(): array
    {
        return [];
    }
}
