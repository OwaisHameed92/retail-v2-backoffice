<?php

namespace App\Domain\Mail\Support;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Shared\Support\Redactor;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Writes the email log. Branded mailables open their row when they are queued (or sent straight away);
 * any other mail (framework notifications) gets a row from the MessageSending listener. The row id travels
 * on the message in the {@see self::HEADER} header so MessageSent can close it.
 *
 * Never stores a body. `meta` goes through the Redactor and only scalars are kept.
 */
final class EmailLogRecorder
{
    public const HEADER = 'X-Email-Log-Id';

    private const MAX_ERROR = 1000;

    /**
     * @param  list<string>  $to
     * @param  array<string, mixed>  $meta
     */
    public function queued(array $to, string $mailable, string $template, ?string $subject, ?string $companyId, array $meta = []): EmailLog
    {
        return EmailLog::query()->create([
            'company_id' => $companyId,
            'to' => $this->joinAddresses($to),
            'mailable' => $mailable,
            'template' => $template,
            'subject' => $subject === null ? null : Str::limit($subject, 250, ''),
            'status' => EmailStatus::Queued,
            'meta' => $this->cleanMeta($meta),
        ]);
    }

    /**
     * Mail that did not come through a branded mailable: open a row from the message itself.
     *
     * @param  array<string, mixed>  $data  View data of the message (only the class names are read).
     */
    public function untracked(Email $message, array $data): EmailLog
    {
        $source = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null;
        $source = is_string($source) ? $source : 'unknown';

        return $this->queued(
            $this->addresses($message),
            $source,
            $source === 'unknown' ? 'other' : Str::kebab(class_basename($source)),
            $message->getSubject(),
            null,
            ['source' => isset($data['__laravel_notification']) ? 'notification' : 'mail'],
        );
    }

    /**
     * An email we chose not to send (demo business, `.invalid` address): logged so staff can see it would have gone.
     *
     * @param  list<string>  $to
     * @param  array<string, mixed>  $meta
     */
    public function suppressed(array $to, string $mailable, string $template, ?string $subject, ?string $companyId, array $meta = []): EmailLog
    {
        $log = $this->queued($to, $mailable, $template, $subject, $companyId, [...$meta, 'suppressed' => 'demo']);
        $log->forceFill(['status' => EmailStatus::Suppressed])->save();

        return $log;
    }

    /**
     * P11: an email held because its category is not sent automatically; an admin sends or discards it later.
     *
     * @param  list<string>  $to
     * @param  array<string, mixed>  $meta
     */
    public function held(array $to, string $mailable, string $template, ?string $subject, ?string $companyId, array $meta = []): EmailLog
    {
        $log = $this->queued($to, $mailable, $template, $subject, $companyId, $meta);
        $log->forceFill(['status' => EmailStatus::Held])->save();

        return $log;
    }

    public function sent(string $id, Email $message, ?string $messageId): void
    {
        EmailLog::query()->whereKey($id)->update([
            'status' => EmailStatus::Sent,
            'to' => $this->joinAddresses($this->addresses($message)),
            'subject' => Str::limit((string) $message->getSubject(), 250, '') ?: null,
            'message_id' => $messageId === null ? null : Str::limit($messageId, 250, ''),
            'sent_at' => now(),
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    public function failed(?string $id, Throwable $exception): void
    {
        if ($id === null) {
            return;
        }

        EmailLog::query()->whereKey($id)->where('status', '!=', EmailStatus::Sent->value)->update([
            'status' => EmailStatus::Failed,
            'error' => $this->describe($exception),
            'updated_at' => now(),
        ]);
    }

    public function exists(string $id): bool
    {
        return EmailLog::query()->whereKey($id)->exists();
    }

    /**
     * @return list<string>
     */
    private function addresses(Email $message): array
    {
        return array_map(fn (Address $address) => $address->getAddress(), $message->getTo());
    }

    /**
     * @param  list<string>  $to
     */
    private function joinAddresses(array $to): string
    {
        $joined = implode(', ', array_values(array_unique(array_filter($to))));

        return $joined === '' ? 'unknown' : Str::limit($joined, 320, '');
    }

    /**
     * Keep only scalar values, redact anything that looks secret, cap the size.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, scalar|null>|null
     */
    private function cleanMeta(array $meta): ?array
    {
        $clean = [];

        foreach (Redactor::redact($meta) ?? [] as $key => $value) {
            if (! is_string($key) || ! (is_scalar($value) || $value === null)) {
                continue;
            }

            $clean[Str::limit($key, 64, '')] = is_string($value) ? Str::limit($value, 200) : $value;
        }

        return $clean === [] ? null : array_slice($clean, 0, 20, true);
    }

    private function describe(Throwable $exception): string
    {
        $message = trim(class_basename($exception).': '.$exception->getMessage());

        return Str::limit($message, self::MAX_ERROR);
    }
}
