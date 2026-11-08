<?php

namespace App\Domain\Pharmacy\Support;

use SensitiveParameter;

/**
 * The `pharmacy.dmd` switch (env `PHARMACY_DMD`, default off) and settings of the dm+d release server (W4,
 * PORTAL-CHANGES-pharmacy.md item 12). Off: the manifest and zip routes answer 501 dmd.not_available and the
 * weekly fetch does nothing. The TRUD key never leaves this class except to TrudClient, and `redact()` takes it
 * (and any `/keys/<key>/` URL part) out of every message before it is logged or stored.
 */
final class DmdSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('pharmacy.dmd.enabled', false);
    }

    public static function hasTrudKey(): bool
    {
        return self::trudKey() !== '';
    }

    /** Our TRUD API key, or '' when none is set. Only for TrudClient. */
    public static function trudKey(): string
    {
        return trim((string) config('pharmacy.dmd.trud_key', ''));
    }

    public static function disk(): string
    {
        return (string) config('pharmacy.dmd.disk', 'local');
    }

    public static function path(): string
    {
        return trim((string) config('pharmacy.dmd.path', 'pharmacy/dmd'), '/');
    }

    public static function keepWeeks(): int
    {
        return max(1, (int) config('pharmacy.dmd.keep_weeks', 2));
    }

    /** How long a zip link handed out by the manifest works: never less than 24 h (contract). */
    public static function linkHours(): int
    {
        return max(24, (int) config('pharmacy.dmd.link_hours', 48));
    }

    public static function perMinute(): int
    {
        return max(2, (int) config('pharmacy.dmd.per_minute', 10));
    }

    public static function downloadsPerMinute(): int
    {
        return max(2, (int) config('pharmacy.dmd.downloads_per_minute', 20));
    }

    public static function retryAfter(): int
    {
        return max(60, (int) config('pharmacy.dmd.not_available_retry_after', 3600));
    }

    /** https base of downloadUrl: PHARMACY_DMD_PUBLIC_URL, else APP_URL, always https. */
    public static function publicBase(): string
    {
        $base = rtrim((string) (config('pharmacy.dmd.public_url') ?: config('app.url')), '/');

        return (string) preg_replace('#^http://#i', 'https://', $base);
    }

    public static function attribution(): string
    {
        return (string) config('pharmacy.dmd.attribution');
    }

    /** A message about a TRUD call with the key and any `/keys/<key>` URL part removed, at most 500 characters. */
    public static function redact(#[SensitiveParameter] string $message): string
    {
        $key = self::trudKey();
        $clean = $key === '' ? $message : str_replace([$key, rawurlencode($key)], '[key]', $message);
        $clean = (string) preg_replace('#/keys/[^/\s?"\']+#i', '/keys/[key]', $clean);

        return mb_substr(trim($clean), 0, 500);
    }
}
