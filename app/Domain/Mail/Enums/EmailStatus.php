<?php

namespace App\Domain\Mail\Enums;

/**
 * Lifecycle of one outgoing email: queued (accepted by us) → sent (handed to the mail server) or failed.
 * A failed attempt that is retried and then succeeds ends as sent. Suppressed: never sent on purpose (a demo
 * business, or an address on the reserved `.invalid` domain), only logged. Held (P11): its category is not sent
 * automatically; it waits for an admin to send it (then queued → sent) or discard it (discarded).
 */
enum EmailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Held = 'held';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Suppressed => 'Not sent (demo)',
            self::Held => 'Held',
            self::Discarded => 'Discarded',
        };
    }

    /**
     * The log's status filter. P11 held and discarded are offered once any email was held (`$withHeld`).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $withHeld = false): array
    {
        $cases = array_filter(self::cases(), fn (self $status) => $withHeld || ! in_array($status, [self::Held, self::Discarded], true));

        return array_values(array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], $cases));
    }
}
