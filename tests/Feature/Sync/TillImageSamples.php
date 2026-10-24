<?php

namespace Tests\Feature\Sync;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/**
 * IMG-1 test pictures made with GD (JPEG, PNG, WebP, GIF) and a JPEG carrying EXIF (camera make and orientation), plus
 * a till product row for a business, as a push would leave it.
 */
final class TillImageSamples
{
    public static function jpeg(int $width = 40, int $height = 30, int $red = 200): string
    {
        return self::draw($width, $height, $red, fn ($image) => imagejpeg($image, null, 90));
    }

    public static function png(int $width = 40, int $height = 30, int $red = 120): string
    {
        return self::draw($width, $height, $red, fn ($image) => imagepng($image));
    }

    public static function webp(int $width = 40, int $height = 30, int $red = 60): string
    {
        return self::draw($width, $height, $red, fn ($image) => imagewebp($image, null, 80));
    }

    public static function gif(int $width = 40, int $height = 30): string
    {
        return self::draw($width, $height, 10, fn ($image) => imagegif($image));
    }

    /**
     * A JPEG with an APP1 Exif segment: IFD0 with Make (ASCII) and Orientation (SHORT), big-endian TIFF.
     */
    public static function jpegWithExif(int $width, int $height, int $orientation, string $make = 'SecretCam GPS 53.79N'): string
    {
        $jpeg = self::jpeg($width, $height);
        $ascii = $make."\0";
        $entries = 2;
        $dataOffset = 8 + 2 + 12 * $entries + 4;
        $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', $entries)
            .pack('nnN', 0x010F, 2, strlen($ascii)).pack('N', $dataOffset)
            .pack('nnN', 0x0112, 3, 1).pack('n', $orientation).pack('n', 0)
            .pack('N', 0).$ascii;
        $segment = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
    }

    /** A till product of the business (written as a push leaves it); returns its id. */
    public static function product(Company $company, string $name = 'Warburtons Toastie 800g', ?string $id = null): string
    {
        $department = OnlineStoreFixtures::department($company, 'Bakery '.Ulid::new(), 1);
        $category = OnlineStoreFixtures::category($company, $department, 'Bread');
        $id ??= Ulid::new();
        DB::table('products')->insert([
            'id' => $id, 'company_id' => $company->id, 'name' => $name, 'short_name' => $name, 'department_id' => $department, 'category_id' => $category,
            'unit_code' => 'each', 'sell_price' => '1.45', 'cost_price' => '0.9800', 'track_stock' => false, 'is_active' => true, 'is_weighed' => false, 'row_version' => 1,
        ]);

        return $id;
    }

    /**
     * @param  callable(\GdImage): mixed  $write
     */
    private static function draw(int $width, int $height, int $red, callable $write): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 90, 40));
        // A white block in the top left corner, so orientation can be checked.
        imagefilledrectangle($image, 0, 0, max(0, intdiv($width, 4) - 1), max(0, intdiv($height, 4) - 1), (int) imagecolorallocate($image, 255, 255, 255));
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }
}
