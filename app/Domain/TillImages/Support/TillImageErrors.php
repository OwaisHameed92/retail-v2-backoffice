<?php

namespace App\Domain\TillImages\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * The picture endpoints' own errors (contract proposal product-images, `error-codes.additions.json`), in the §9 body
 * `{code, message, traceId, retryAfterSeconds, rejectedKey}`. `rejectedKey` is the product id where one is known.
 * Messages are en-GB for the shop owner and never contain a key.
 */
final class TillImageErrors
{
    public static function notAvailable(): ApiException
    {
        return new ApiException('image.not_available', 'Product pictures are not switched on for the portal yet. The till keeps its pictures and tries again later.', 501,
            max(60, (int) config('sync.product_images.not_available_retry_after', 3600)));
    }

    public static function productNotFound(string $productId): ApiException
    {
        return new ApiException('product.not_found', 'The portal does not have this product yet. The till sends the picture again after its next sync.', 404, null, $productId);
    }

    public static function tooLarge(string $productId): ApiException
    {
        $mb = rtrim(rtrim(number_format(TillImageSwitch::maxBytes() / 1048576, 1), '0'), '.');

        return new ApiException('image.too_large', "This picture is larger than {$mb} MB. Make it smaller and send it again.", 413, null, $productId);
    }

    public static function unsupportedType(string $productId): ApiException
    {
        return new ApiException('image.unsupported_type', 'Only JPEG, PNG and WebP pictures can be sent, with a Content-Type that matches the picture.', 415, null, $productId);
    }

    public static function hashMismatch(string $productId): ApiException
    {
        return new ApiException('image.hash_mismatch', 'The picture did not arrive whole (its SHA-256 does not match). The till sends it again.', 422, null, $productId);
    }

    public static function dimensions(string $productId): ApiException
    {
        $min = TillImageSwitch::minPixels();
        $max = TillImageSwitch::maxPixels();

        return new ApiException('image.dimensions', "The picture must be {$min} to {$max} pixels wide and high.", 422, null, $productId);
    }

    public static function unreadable(string $productId): ApiException
    {
        return new ApiException('image.invalid', 'The portal could not read this picture. Choose another picture for the product.', 422, null, $productId);
    }
}
