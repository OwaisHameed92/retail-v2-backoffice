<?php

namespace App\Domain\TillImages\Support;

/**
 * The `sync.product_images` switch (env `TILL_PRODUCT_IMAGES`, default off) and limits of contract proposal
 * product-images. Off: the picture endpoints answer 501 image.not_available before the body is read, and the online
 * store and the Products pages never read `product_images` (exactly as before IMG-1).
 */
final class TillImageSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('sync.product_images.enabled', false);
    }

    public static function maxBytes(): int
    {
        return (int) config('sync.product_images.max_bytes', 2 * 1024 * 1024);
    }

    public static function maxPixels(): int
    {
        return (int) config('sync.product_images.max_pixels', 2000);
    }

    public static function minPixels(): int
    {
        return (int) config('sync.product_images.min_pixels', 16);
    }

    public static function storedMaxPixels(): int
    {
        return (int) config('sync.product_images.stored_max_pixels', 1200);
    }

    public static function perMinute(): int
    {
        return max(6, (int) config('sync.product_images.per_minute', 60));
    }
}
