<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Mail\Support\EmailLogRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for every Switch & Save email: queued (after the DB commit), encrypted on the queue (payloads can hold
 * licence keys or password links), styled with the "switch-save" Markdown theme, and written to the email log.
 *
 * Log lifecycle: a row is opened as "queued" when the mail is queued (or sent directly), the MessageSent
 * listener marks it "sent", and a failed attempt or failed job marks it "failed".
 *
 * Usage: Mail::to($owner->email)->queue(new WelcomeTenantMail($data));
 */
abstract class BrandedMailable extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    /** Markdown theme in resources/views/vendor/mail/html/themes. */
    public $theme = 'switch-save';

    /** Attempts before the job is failed (and the log row stays "failed"). */
    public int $tries = 3;

    /** Email log row for this message, set when it is queued or sent. */
    public ?string $emailLogId = null;

    /** A test send from the admin template screen: subject gets a "[Test]" prefix. */
    public bool $isTest = false;

    /** P11: an admin sends it (a held email released, or a "Send" button): never held. */
    public bool $manualSend = false;

    /** P11: the hold check ran when it was queued, so the queue worker does not run it again. */
    public bool $holdChecked = false;

    /** Stable key used in the log and the admin template list, e.g. "welcome-tenant". */
    abstract public static function templateKey(): string;

    /** Name shown in the admin template list. */
    abstract public static function templateLabel(): string;

    /** One line on when and why the email is sent. */
    abstract public static function templateDescription(): string;

    /** Who receives it: "customer" or "staff". */
    public static function audience(): string
    {
        return 'customer';
    }

    /** A realistic example used for previews and test sends. Never real customer data. */
    abstract public static function sample(): static;

    /** The subject, without the test prefix. */
    abstract public function subjectLine(): string;

    public function companyId(): ?string
    {
        return null;
    }

    /**
     * P11: the category whose "Send automatically" setting decides whether it goes by itself (Admin → Settings →
     * Emails). Null = always sent (user-initiated mail, tenant-sent mail, staff mail).
     */
    public function emailCategory(): ?EmailCategory
    {
        return null;
    }

    /**
     * Non-secret facts stored with the log row (scalars only, redacted again on write).
     * Never put licence keys, links with tokens or personal details beyond the business name here.
     *
     * @return array<string, scalar|null>
     */
    public function logMeta(): array
    {
        return [];
    }

    /**
     * Recipients fixed by the template itself (staff alerts). Customer emails get theirs from Mail::to().
     *
     * @return list<Address>
     */
    protected function defaultRecipients(): array
    {
        return [];
    }

    public function asTest(): static
    {
        $this->isTest = true;

        return $this;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->isTest ? [] : $this->defaultRecipients(),
            replyTo: [new Address((string) config('sspos.support_email'), 'Switch & Save support')],
            subject: ($this->isTest ? '[Test] ' : '').$this->subjectLine(),
            tags: [static::templateKey()],
        );
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @return mixed
     */
    public function queue(Queue $queue)
    {
        if ($this->suppress() || $this->hold()) {
            return null;
        }

        $this->openLog();
        $this->afterCommit ??= true;

        return parent::queue($queue);
    }

    /**
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @return mixed
     */
    public function later($delay, Queue $queue)
    {
        if ($this->suppress() || $this->hold()) {
            return null;
        }

        $this->openLog();
        $this->afterCommit ??= true;

        return parent::later($delay, $queue);
    }

    /**
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        if ($this->suppress() || $this->hold()) {
            return null;
        }

        $this->openLog();

        try {
            return parent::send($mailer);
        } catch (Throwable $exception) {
            app(EmailLogRecorder::class)->failed($this->emailLogId, $exception);

            throw $exception;
        }
    }

    /** Called by the queue when every attempt has failed. */
    public function failed(Throwable $exception): void
    {
        app(EmailLogRecorder::class)->failed($this->emailLogId, $exception);
    }

    /**
     * The plain-text part, for the admin preview.
     */
    public function renderText(): string
    {
        return $this->withLocale($this->locale, function (): string {
            $this->prepareMailableForDelivery();

            $data = $this->buildViewData();

            return (string) ($this->buildMarkdownText($data))($data);
        });
    }

    /**
     * Demo businesses (demo:billing) never get an email, and nothing goes to a reserved `.invalid` address: the
     * message is logged as "suppressed" instead of being queued or sent. Test sends from the template screen go.
     */
    private function suppress(): bool
    {
        if ($this->isTest) {
            return false;
        }

        $to = $this->recipientAddresses();
        $undeliverable = $to !== [] && array_filter($to, fn (string $email) => ! DemoBusinesses::isUndeliverable($email)) === [];

        if (! $undeliverable && ! DemoBusinesses::isDemo($this->companyId())) {
            return false;
        }

        if ($this->emailLogId === null) {
            $this->emailLogId = app(EmailLogRecorder::class)->suppressed($to, static::class, static::templateKey(), $this->subjectLine(), $this->companyId(), $this->logMeta())->id;
            Log::info('Email not sent: demo business or reserved address', ['template' => static::templateKey(), 'company_id' => $this->companyId()]);
        }

        return true;
    }

    /**
     * P11: a categorised email whose category the owner switched off is held (logged "Held") instead of sent, unless
     * an admin is sending it. Checked once per message (a queued one is not checked again by the worker).
     */
    private function hold(): bool
    {
        if ($this->isTest || $this->manualSend || $this->holdChecked) {
            return false;
        }

        $this->holdChecked = true;

        return EmailControl::shouldHold($this, $this->recipientAddresses());
    }

    /** Open the log row once per message (queued jobs keep the id when they are unserialised). */
    private function openLog(): void
    {
        $recorder = app(EmailLogRecorder::class);

        if ($this->emailLogId !== null && $recorder->exists($this->emailLogId)) {
            return;
        }

        $meta = $this->logMeta();

        if ($this->isTest) {
            $meta['test'] = true;
        }

        $this->emailLogId = $recorder->queued(
            $this->recipientAddresses(),
            static::class,
            static::templateKey(),
            ($this->isTest ? '[Test] ' : '').$this->subjectLine(),
            $this->companyId(),
            $meta,
        )->id;
    }

    /**
     * @return list<string>
     */
    private function recipientAddresses(): array
    {
        $addresses = array_map(fn (array $recipient) => (string) $recipient['address'], $this->to);

        foreach ($this->isTest ? [] : $this->defaultRecipients() as $address) {
            $addresses[] = $address->address;
        }

        return array_values(array_unique($addresses));
    }
}
