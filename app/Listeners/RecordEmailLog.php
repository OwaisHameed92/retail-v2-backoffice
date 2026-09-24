<?php

namespace App\Listeners;

use App\Domain\Mail\Support\EmailLogRecorder;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Email;

/**
 * Keeps the email log in step with the mailer (auto-discovered from app/Listeners).
 *
 * - MessageSending: find the row the branded mailable opened (or open one for any other mail) and tag the
 *   message with its id in the X-Email-Log-Id header.
 * - MessageSent: mark that row sent with the transport's message id.
 *
 * Only class names and the log id are read from the view data: never the body or data objects.
 */
final class RecordEmailLog
{
    public function __construct(private readonly EmailLogRecorder $recorder) {}

    public function handleSending(MessageSending $event): void
    {
        $message = $event->message;

        $id = $event->data['emailLogId'] ?? null;

        if (! is_string($id) || ! $this->recorder->exists($id)) {
            $id = $this->recorder->untracked($message, $event->data)->id;
        }

        $headers = $message->getHeaders();
        $headers->remove(EmailLogRecorder::HEADER);
        $headers->addTextHeader(EmailLogRecorder::HEADER, $id);
    }

    public function handleSent(MessageSent $event): void
    {
        $message = $event->sent->getOriginalMessage();

        if (! $message instanceof Email) {
            return;
        }

        $id = $message->getHeaders()->get(EmailLogRecorder::HEADER)?->getBodyAsString();

        if ($id === null || $id === '') {
            return;
        }

        $this->recorder->sent($id, $message, $event->sent->getMessageId());
    }
}
