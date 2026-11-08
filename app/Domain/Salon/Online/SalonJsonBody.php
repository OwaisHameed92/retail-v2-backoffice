<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Support\SalonApiErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Support\SyncApiErrors;
use JsonException;

/**
 * Reads the JSON body of a `sync/salon/*` call (W3a, README §3 "Bodies"): the booking page comes gzipped
 * (`Content-Encoding: gzip`), the others plain; a gzip body is also recognised by its magic bytes. Inflated in small
 * steps so a compressed bomb stops at the limit instead of filling memory.
 *
 * - over the limit once unzipped → 413 salon.page_too_large (the booking page) or 400 request.invalid (the others);
 * - another encoding, broken gzip, not JSON → 400 request.invalid.
 *
 * Never logs or repeats the body (it carries the manage-link key and clients' first names).
 */
final class SalonJsonBody
{
    private const STEP = 16384;

    /** The small calls (heartbeat, join answer, queue) are a few hundred bytes; a full day's queue is well under this. */
    public const SMALL_MAX_BYTES = 1024 * 1024;

    /**
     * @return array{data: mixed, json: string}
     *
     * @throws ApiException
     */
    public static function read(string $raw, ?string $encoding, int $max, bool $page): array
    {
        $encoding = strtolower(trim((string) $encoding));

        if (! in_array($encoding, ['', 'identity', 'gzip', 'x-gzip'], true)) {
            throw SyncApiErrors::invalid('Send the salon call as gzip or plain JSON.');
        }

        $json = $encoding !== 'identity' && str_starts_with($raw, "\x1f\x8b") ? self::gunzip($raw, $max, $page) : $raw;

        if (strlen($json) > $max) {
            throw self::tooLarge($page);
        }

        try {
            return ['data' => json_decode($json, true, 64, JSON_THROW_ON_ERROR), 'json' => $json];
        } catch (JsonException) {
            throw SyncApiErrors::invalid('The salon call\'s body is not valid JSON.');
        }
    }

    private static function tooLarge(bool $page): ApiException
    {
        return $page ? SalonApiErrors::pageTooLarge() : SyncApiErrors::invalid('The salon call\'s body is larger than the portal accepts.');
    }

    /**
     * @throws ApiException
     */
    private static function gunzip(string $raw, int $max, bool $page): string
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
                throw self::tooLarge($page);
            }
        }

        if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            throw SyncApiErrors::invalid('The gzip body is incomplete.');
        }

        return $out;
    }
}
