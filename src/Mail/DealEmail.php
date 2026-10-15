<?php

namespace Sellinnate\SalesCrm\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Sellinnate\SalesCrm\Models\Email;

/**
 * An email written from a deal, as plain text, with the Message-ID of the CRM and the threading
 * headers of the email it answers.
 */
class DealEmail extends Mailable
{
    public function __construct(public Email $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->email->from_email, $this->email->from_name),
            to: $this->email->to,
            cc: $this->email->cc ?? [],
            replyTo: $this->email->reply_to !== null ? [new Address($this->email->reply_to)] : [],
            subject: $this->email->subject,
        );
    }

    public function headers(): Headers
    {
        $references = array_values(array_filter(preg_split('/\s+/', (string) $this->email->references) ?: []));

        return new Headers(
            messageId: $this->email->message_id,
            references: $references,
            text: $this->email->in_reply_to !== null ? ['In-Reply-To' => '<'.$this->email->in_reply_to.'>'] : [],
        );
    }

    public function content(): Content
    {
        /** @var view-string $view */
        $view = 'sales-crm::mail.deal-email';

        return new Content(text: $view);
    }
}
