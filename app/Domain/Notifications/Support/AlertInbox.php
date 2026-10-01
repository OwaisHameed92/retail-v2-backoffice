<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertNotification;
use Illuminate\Support\Str;

/**
 * Writes the notifications bell (module 7.8). Called by the alert jobs for a known business.
 */
final class AlertInbox
{
    public static function add(string $companyId, int $userId, AlertType|string $type, string $tone, string $title, ?string $body, ?string $url): AlertNotification
    {
        return AlertNotification::withoutCompanyScope()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'alert_type' => $type instanceof AlertType ? $type->value : $type,
            'tone' => $tone,
            'title' => Str::limit($title, 197),
            'body' => $body === null ? null : Str::limit($body, 497),
            'url' => $url === null ? null : Str::limit(self::relative($url), 500, ''),
        ]);
    }

    /** Portal links are kept as paths ("/app/stock?…") so the bell opens them in the current portal. */
    private static function relative(string $url): string
    {
        $portal = rtrim((string) config('sspos.portal_url'), '/');

        return $portal !== '' && str_starts_with($url, $portal.'/') ? substr($url, strlen($portal)) : $url;
    }
}
