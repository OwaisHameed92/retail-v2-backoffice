<?php

namespace App\Domain\Mail\Enums;

/**
 * Lifecycle of one outgoing email: queued (accepted by us) → sent (handed to the mail server) or failed.
 * A failed attempt that is retried and then succeeds ends as sent. Suppressed: never sent on purpose (a demo
 * business, or an address on the reserved `.invalid` domain), only logged.
 */
enum EmailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Suppressed => 'Not sent (demo)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
