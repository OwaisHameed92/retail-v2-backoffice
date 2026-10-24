<?php

namespace App\Domain\OnlineStore\Support;

use GdImage;
use Illuminate\Validation\ValidationException;

/**
 * Audit OSA-15: an uploaded store picture is never kept as uploaded. It is decoded with GD (ext-gd; no image
 * library is installed), turned upright from its EXIF orientation, scaled down to fit `$max` × `$max` pixels (never
 * up), and encoded again as WebP (JPEG where this PHP's GD has no WebP). Re-encoding drops every bit of metadata:
 * EXIF, GPS, XMP, ICC and comments. Transparency is kept in WebP. The original bytes are never stored.
 *
 *     ['bytes' => $webp, 'extension' => 'webp'] = StoreImageEncoder::encode($file->getRealPath(), 1200, 'photo');
 */
final class StoreImageEncoder
{
    /** Pictures larger than this are refused before decoding (GD needs about 4 bytes per pixel). */
    public const MAX_PIXELS = 50_000_000;

    private const WEBP_QUALITY = 82;

    private const JPEG_QUALITY = 85;

    /** Whether this server can re-encode pictures; without it uploads are refused, never stored as they came. */
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && (function_exists('imagewebp') || function_exists('imagejpeg'));
    }

    /**
     * @return array{bytes: string, extension: string, width: int, height: int}
     *
     * @throws ValidationException when the picture cannot be read or this server cannot process pictures
     */
    public static function encode(string $path, int $max, string $field): array
    {
        if (! self::available()) {
            throw ValidationException::withMessages([$field => 'Pictures cannot be uploaded right now. Try again later or contact Switch & Save.']);
        }

        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw ValidationException::withMessages([$field => 'Choose a JPG, PNG or WebP picture.']);
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([$field => 'That picture is too large. Choose one under 50 megapixels.']);
        }

        $limit = (string) ini_get('memory_limit');
        self::raiseMemory($limit);

        try {
            $bytes = (string) file_get_contents($path);
            $image = @imagecreatefromstring($bytes);

            if (! $image instanceof GdImage) {
                throw ValidationException::withMessages([$field => 'That picture could not be read. Choose another one.']);
            }

            $image = self::upright($image, $size[2] === IMAGETYPE_JPEG ? self::orientation($path) : 1);
            $image = self::fit($image, $max);

            return [...self::output($image), 'width' => imagesx($image), 'height' => imagesy($image)];
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    /** The EXIF orientation (1 to 8) of a JPEG, 1 when there is none or it cannot be read. */
    private static function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);
        $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    private static function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** Scales down (never up) to fit max × max, on a true-colour canvas that keeps transparency. */
    private static function fit(GdImage $image, int $max): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $max / max($width, $height));
        [$w, $h] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $canvas;
    }

    /**
     * @return array{bytes: string, extension: string}
     */
    private static function output(GdImage $image): array
    {
        ob_start();

        if (function_exists('imagewebp')) {
            imagewebp($image, null, self::WEBP_QUALITY);

            return ['bytes' => (string) ob_get_clean(), 'extension' => 'webp'];
        }

        // JPEG has no transparency: flatten onto white.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imageinterlace($flat, true);
        imagejpeg($flat, null, self::JPEG_QUALITY);

        return ['bytes' => (string) ob_get_clean(), 'extension' => 'jpg'];
    }

    private static function raiseMemory(string $limit): void
    {
        $bytes = self::bytes($limit);

        if ($bytes !== -1 && $bytes < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
