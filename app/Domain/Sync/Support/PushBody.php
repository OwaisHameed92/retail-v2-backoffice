<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;
use JsonException;

/**
 * Reads a `sync/push` body (contract §3, §17.11 rule 13): gzip (`Content-Encoding: gzip`, what the till sends) or
 * plain JSON (test tools). A gzip body is recognised by its magic bytes too. Inflated in small steps so a
 * compressed bomb stops at the limit instead of filling memory.
 *
 * - more than config('sync.push.max_bytes') after gunzip, or more than config('sync.push.max_rows') rows → 413
 *   batch.too_large;
 * - another encoding, broken gzip, bad JSON, not a non-empty array → 400 request.invalid.
 */
final class PushBody
{
    private const STEP = 16384;

    /**
     * @return array{changes: list<mixed>, fingerprint: string} fingerprint = SHA-256 of the JSON (Idempotency-Key)
     *
     * @throws ApiException
     */
    public static function decode(string $raw, ?string $encoding): array
    {
        $encoding = strtolower(trim((string) $encoding));
        $max = max(1, (int) config('sync.push.max_bytes'));

        if (! in_array($encoding, ['', 'identity', 'gzip', 'x-gzip'], true)) {
            throw SyncApiErrors::invalid('Send the batch as gzip or plain JSON.');
        }

        $json = $encoding !== 'identity' && str_starts_with($raw, "\x1f\x8b") ? self::gunzip($raw, $max) : $raw;

        if (strlen($json) > $max) {
            throw SyncApiErrors::tooLarge('This batch is larger than the portal accepts in one request.');
        }

        try {
            $changes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SyncApiErrors::invalid('The batch is not valid JSON.');
        }

        if (! is_array($changes) || $changes === [] || ! array_is_list($changes)) {
            throw SyncApiErrors::invalid('The batch must be a list of changes.');
        }

        $rows = max(1, (int) config('sync.push.max_rows'));

        if (count($changes) > $rows) {
            throw SyncApiErrors::tooLarge("This batch has more than {$rows} rows.");
        }

        return ['changes' => $changes, 'fingerprint' => hash('sha256', $json)];
    }

    /**
     * @throws ApiException
     */
    private static function gunzip(string $raw, int $max): string
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $out = '';

        if ($inflate === false) {
            throw SyncApiErrors::invalid('The gzip body could not be read.');
        }

        for ($offset = 0, $length = strlen($raw); $offset < $length; $offset += self::STEP) {
            $part = @inflate_add($inflate, substr($raw, $offset, self::STEP), ZLIB_SYNC_FLUSH);

            if ($part === false) {
                throw SyncApiErrors::invalid('The gzip body is damaged.');
            }

            $out .= $part;

            if (strlen($out) > $max) {
                throw SyncApiErrors::tooLarge('This batch is larger than the portal accepts in one request.');
            }
        }

        if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            throw SyncApiErrors::invalid('The gzip body is incomplete.');
        }

        return $out;
    }
}
