<?php

namespace App\Domain\TillImages\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Where till product pictures live: the `public` disk, `till-images/{company_id}/{product_id}-{hash16}.{webp|jpg}`
 * (hash16 = the first 16 hex characters of the till's sha256, so a replaced picture gets a new address and caches
 * never show the old one). A business only ever deletes files in its own folder.
 */
final class TillImageFiles
{
    public const DISK = 'public';

    public const ROOT = 'till-images';

    public static function folder(string $companyId): string
    {
        return self::ROOT.'/'.$companyId;
    }

    public static function path(string $companyId, string $productId, string $hash, string $extension): string
    {
        return self::folder($companyId).'/'.$productId.'-'.substr($hash, 0, 16).'.'.$extension;
    }

    public static function put(string $path, string $bytes): bool
    {
        return Storage::disk(self::DISK)->put($path, $bytes);
    }

    public static function delete(string $companyId, ?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::folder($companyId).'/') && ! str_contains($path, '..')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        return $path === null || $path === '' ? null : Storage::disk(self::DISK)->url($path);
    }
}
