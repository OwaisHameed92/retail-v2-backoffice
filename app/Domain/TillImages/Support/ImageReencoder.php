<?php

namespace App\Domain\TillImages\Support;

use App\Domain\TillImages\Data\EncodedImage;
use GdImage;

/**
 * Checks a picture a till sent and makes the portal's web copy (contract proposal product-images §4):
 *
 * - the type is read from the bytes (never trusted from the header): JPEG, PNG or WebP only, and it must be the
 *   declared `Content-Type` → else 415 image.unsupported_type;
 * - width and height within the limits, read from the header before anything is decoded (no decompression bomb) →
 *   else 422 image.dimensions; bytes GD cannot decode (also animated WebP) → 422 image.invalid;
 * - a JPEG's EXIF orientation is applied, then the picture is drawn again on a fresh canvas and saved as WebP (JPEG
 *   when GD has no WebP), at most `stored_max_pixels` on its longest side. Nothing of the original file is kept, so
 *   EXIF (camera, GPS, time), ICC and comments are gone.
 */
final class ImageReencoder
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const WEBP_QUALITY = 82;

    private const JPEG_QUALITY = 85;

    public function encode(string $productId, string $bytes, string $declaredType): EncodedImage
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw TillImageErrors::unreadable($productId);
        }

        $type = $info['mime'];

        if (! in_array($type, self::TYPES, true) || $type !== $declaredType) {
            throw TillImageErrors::unsupportedType($productId);
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $min = TillImageSwitch::minPixels();
        $max = TillImageSwitch::maxPixels();

        if ($width < $min || $height < $min || $width > $max || $height > $max) {
            throw TillImageErrors::dimensions($productId);
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw TillImageErrors::unreadable($productId);
        }

        $image = $this->upright($this->truecolour($image), $type === 'image/jpeg' ? $this->orientation($bytes) : 1);
        $image = $this->fit($image, TillImageSwitch::storedMaxPixels());

        return $this->save($productId, $image);
    }

    private function truecolour(GdImage $image): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** The EXIF orientation of a JPEG (1 when it has none or it cannot be read). */
    private function orientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return 1;
        }

        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_numeric($value) && (int) $value >= 1 && (int) $value <= 8 ? (int) $value : 1;
    }

    private function upright(GdImage $image, int $orientation): GdImage
    {
        $rotate = fn (GdImage $img, int $angle): GdImage => ($turned = imagerotate($img, $angle, 0)) instanceof GdImage ? $turned : $img;

        $image = match ($orientation) {
            3 => $rotate($image, 180),
            5, 6 => $rotate($image, -90),
            7, 8 => $rotate($image, 90),
            default => $image,
        };

        match ($orientation) {
            2, 5, 7 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4 => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $this->truecolour($image);
    }

    private function fit(GdImage $image, int $longest): GdImage
    {
        [$width, $height] = [imagesx($image), imagesy($image)];

        if (max($width, $height) <= $longest) {
            return $image;
        }

        $scale = $longest / max($width, $height);
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);

        return $canvas;
    }

    private function save(string $productId, GdImage $image): EncodedImage
    {
        $webp = function_exists('imagewebp');

        if (! $webp) {
            // JPEG has no transparency: put the picture on white.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $image = $flat;
        }

        ob_start();
        $saved = $webp ? imagewebp($image, null, self::WEBP_QUALITY) : imagejpeg($image, null, self::JPEG_QUALITY);
        $out = (string) ob_get_clean();

        if (! $saved || $out === '') {
            throw TillImageErrors::unreadable($productId);
        }

        return new EncodedImage($out, $webp ? 'webp' : 'jpg', imagesx($image), imagesy($image));
    }
}
