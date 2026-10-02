<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The scheduled network report: summary in the email, Users report PDF attached. */
class NetworkReportMail extends Mailable
{
    public function __construct(public array $summary, private string $pdf, private string $file, private string $frequency)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->frequency.' network report: '.$this->summary['to']->format('M j, Y').' | Public WiFi Control');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.network-report', with: ['s' => $this->summary, 'frequency' => $this->frequency]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->file)->withMime('application/pdf')];
    }
}
