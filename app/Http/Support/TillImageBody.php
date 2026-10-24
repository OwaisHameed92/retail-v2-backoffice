<?php

namespace App\Http\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\TillImages\Data\TillImageUpload;
use App\Domain\TillImages\Support\ImageReencoder;
use App\Domain\TillImages\Support\TillImageErrors;
use App\Domain\TillImages\Support\TillImageSwitch;
use Illuminate\Http\Request;

/**
 * Reads `PUT sync/product-images/{productId}` (contract proposal product-images §4.1) like a form request: the raw body
 * is the picture. Checked before any of it is stored:
 *
 * - the product id is a ULID; `X-SSPOS-Image-Sha256` is 64 hex characters; `Content-Length` is given and the body
 *   is not gzip → else 400 request.invalid;
 * - `Content-Type` is image/jpeg, image/png or image/webp → else 415 image.unsupported_type;
 * - `Content-Length` (and the bytes really read) at most `max_bytes` → else 413 image.too_large, without reading a
 *   larger body into memory.
 */
final class TillImageBody
{
    public const HASH_HEADER = 'X-SSPOS-Image-Sha256';

    /**
     * @throws ApiException
     */
    public static function read(Request $request, string $productId): TillImageUpload
    {
        $productId = self::productId($productId);
        $hash = strtolower(trim((string) $request->header(self::HASH_HEADER)));
        $length = trim((string) $request->header('Content-Length'));
        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw SyncApiErrors::invalid('Send '.self::HASH_HEADER.': the SHA-256 of the picture, 64 hex characters.');
        }

        if ($request->header('Content-Encoding') !== null && strtolower(trim((string) $request->header('Content-Encoding'))) !== 'identity') {
            throw SyncApiErrors::invalid('Send the picture as it is, without Content-Encoding.');
        }

        if (! in_array($type, ImageReencoder::TYPES, true)) {
            throw TillImageErrors::unsupportedType($productId);
        }

        if (! ctype_digit($length)) {
            throw SyncApiErrors::invalid('Send Content-Length with the picture.');
        }

        $max = TillImageSwitch::maxBytes();

        if ((int) $length > $max) {
            throw TillImageErrors::tooLarge($productId);
        }

        $stream = $request->getContent(true);
        $bytes = is_resource($stream) ? (string) stream_get_contents($stream, $max + 1) : '';

        if (strlen($bytes) > $max) {
            throw TillImageErrors::tooLarge($productId);
        }

        if ($bytes === '') {
            throw SyncApiErrors::invalid('The picture is empty.');
        }

        return new TillImageUpload($productId, $bytes, $type, $hash);
    }

    /**
     * @throws ApiException
     */
    public static function productId(string $productId): string
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/', $productId) !== 1) {
            throw SyncApiErrors::invalid('The product id in the address must be the product\'s ULID.');
        }

        return $productId;
    }
}
