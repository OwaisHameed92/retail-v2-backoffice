<?php

namespace App\Domain\Mail\Contracts;

/**
 * Builds a file for an email at send time (in the queue worker), so the queued payload only carries an id.
 * Mail data names the renderer class and a key; the mailable resolves the class from the container.
 * Used by InvoiceMail for the invoice PDF (implemented in App\Domain\Billing\Support\InvoicePdf).
 */
interface RendersAttachment
{
    /** The file's bytes, or null when the document no longer exists (the email is then sent without it). */
    public function renderAttachment(string $key): ?string;
}
