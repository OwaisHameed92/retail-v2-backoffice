<?php

namespace App\Domain\Mail\Support;

use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\EmailSetting;
use App\Domain\Mail\Models\HeldEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Which tenant emails go out by themselves (P11, owner 2026-10-07). Each EmailCategory has "Send automatically"
 * (Admin → Settings → Emails); no setting = on, so nothing changes until the owner switches one off.
 *
 * BrandedMailable asks shouldHold() before it queues or sends: a categorised email whose category is off is not
 * sent but held (EmailLog status "held" + a HeldEmail with the encrypted message) for an admin to send or discard.
 * An admin's own "Send" actions run inside manually(), which is never held. Billing logic (invoices, reminders'
 * timing, suspension, Direct Debit) does not look at any of this: only the emails are held.
 */
final class EmailControl
{
    /** Depth of manually() calls in this process. */
    private static int $manual = 0;

    public static function sendsAutomatically(EmailCategory $category): bool
    {
        try {
            $setting = EmailSetting::query()->find($category->value);
        } catch (Throwable) {
            return true; // no settings table (fresh install mid-migration): behave as before
        }

        return $setting === null || $setting->send_automatically;
    }

    /**
     * @return array<string, bool> category value => send automatically
     */
    public static function all(): array
    {
        $stored = EmailSetting::query()->pluck('send_automatically', 'category')->all();
        $settings = [];

        foreach (EmailCategory::cases() as $category) {
            $settings[$category->value] = (bool) ($stored[$category->value] ?? true);
        }

        return $settings;
    }

    /**
     * Run an admin's explicit send: emails queued inside are never held.
     *
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    public static function manually(callable $send): mixed
    {
        self::$manual++;

        try {
            return $send();
        } finally {
            self::$manual--;
        }
    }

    public static function isManual(): bool
    {
        return self::$manual > 0;
    }

    /**
     * True (and the email is recorded as held) when this email must wait for an admin.
     *
     * @param  list<string>  $to
     */
    public static function shouldHold(BrandedMailable $mailable, array $to): bool
    {
        $category = $mailable->emailCategory();

        if ($category === null || self::isManual() || self::toStaffOnly($to) || self::sendsAutomatically($category)) {
            return false;
        }

        self::hold($mailable, $category, $to);

        return true;
    }

    /**
     * Our own copies (the staff address) are never held.
     *
     * @param  list<string>  $to
     */
    private static function toStaffOnly(array $to): bool
    {
        $staff = Str::lower(trim((string) config('sspos.staff_email')));

        return $to !== [] && $staff !== '' && array_filter($to, fn (string $email) => Str::lower(trim($email)) !== $staff) === [];
    }

    /**
     * @param  list<string>  $to
     */
    private static function hold(BrandedMailable $mailable, EmailCategory $category, array $to): void
    {
        $log = app(EmailLogRecorder::class)->held($to, $mailable::class, $mailable::templateKey(), $mailable->subjectLine(), $mailable->companyId(), $mailable->logMeta());
        $mailable->emailLogId = $log->id;

        HeldEmail::query()->create([
            'email_log_id' => $log->id,
            'company_id' => $mailable->companyId(),
            'category' => $category,
            'template' => $mailable::templateKey(),
            'to' => $log->to,
            'subject' => Str::limit($mailable->subjectLine(), 250, ''),
            'payload' => serialize($mailable),
            'status' => HeldEmail::HELD,
        ]);

        Log::info('Email held: its category is not sent automatically', ['template' => $mailable::templateKey(), 'company_id' => $mailable->companyId(), 'category' => $category->value]);
    }

    /** The log row of a held email, for its status changes. */
    public static function logOf(HeldEmail $held): ?EmailLog
    {
        return $held->email_log_id === null ? null : EmailLog::query()->find($held->email_log_id);
    }
}
