<?php

use App\Domain\OnlineStore\Support\StoreImageEncoder;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Audit OSA-15: store pictures are re-encoded (GD), stripped of EXIF / GPS, turned upright, scaled down per kind
| (logo 600, product 1200, banner 2400 px) and stored as WebP. The upload itself is never kept.
*/

/** A JPEG with an EXIF block: Orientation 6 (turn 90° clockwise) and a fake GPS marker string. */
function jpegWithExif(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    // TIFF header (big-endian), one IFD entry: 0x0112 Orientation, SHORT, 1, value 6; then a marker the test looks for.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff.'GPSLatitude=53.79N-SECRET-HOME';
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $bytes = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
}

beforeEach(function () {
    Storage::fake('public');
    $this->company = Company::factory()->create();
});

test('a phone photo loses its EXIF and GPS, is turned upright and stored as WebP', function () {
    $file = jpegWithExif(400, 200);
    expect((string) file_get_contents((string) $file->getRealPath()))->toContain('SECRET-HOME');

    $path = StoreImages::put($this->company, $file, StoreImages::PRODUCT, 'photo');
    $stored = Storage::disk('public')->get($path);

    expect($path)->toEndWith('.webp')
        ->and(substr((string) $stored, 0, 4))->toBe('RIFF')
        ->and(substr((string) $stored, 8, 4))->toBe('WEBP')
        ->and($stored)->not->toContain('SECRET-HOME')
        ->and($stored)->not->toContain('Exif');

    // Orientation 6: the 400 × 200 picture is stored upright, 200 × 400.
    $size = getimagesizefromstring((string) $stored);
    expect([$size[0], $size[1]])->toBe([200, 400]);
});

test('pictures are scaled down to fit their kind and never up', function (string $folder, int $width, int $height, array $expected) {
    $path = StoreImages::put($this->company, UploadedFile::fake()->image('p.png', $width, $height), $folder);
    $size = getimagesizefromstring((string) Storage::disk('public')->get($path));

    expect([$size[0], $size[1]])->toBe($expected);
})->with([
    'a big logo' => [StoreImages::LOGO, 2000, 1000, [600, 300]],
    'a tall product photo' => [StoreImages::PRODUCT, 1500, 3000, [600, 1200]],
    'a wide banner' => [StoreImages::BANNER, 4800, 1600, [2400, 800]],
    'a small photo stays its size' => [StoreImages::PRODUCT, 300, 200, [300, 200]],
]);

test('only the re-encoded picture is stored, never the upload', function () {
    $file = UploadedFile::fake()->image('original.jpg', 1600, 700);
    $original = (string) file_get_contents((string) $file->getRealPath());

    $path = StoreImages::put($this->company, $file, StoreImages::BANNER, 'photo');

    $files = Storage::disk('public')->allFiles();
    expect($files)->toBe([$path])
        ->and(Storage::disk('public')->get($path))->not->toBe($original)
        ->and($path)->toStartWith('online-store/'.$this->company->id.'/banner/');
});

test('a PNG logo keeps its transparency', function () {
    $image = imagecreatetruecolor(100, 100);
    imagesavealpha($image, true);
    imagealphablending($image, false);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    $path = tempnam(sys_get_temp_dir(), 'png').'.png';
    imagepng($image, $path);

    $stored = StoreImages::put($this->company, new UploadedFile($path, 'logo.png', 'image/png', null, true), StoreImages::LOGO);
    $decoded = imagecreatefromstring((string) Storage::disk('public')->get($stored));

    expect($decoded)->toBeInstanceOf(GdImage::class)
        ->and((imagecolorat($decoded, 50, 50) >> 24) & 0x7F)->toBe(127);
});

test('a file that is not a picture is refused and nothing is stored', function () {
    $file = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;');

    expect(fn () => StoreImages::put($this->company, $file, StoreImages::LOGO, 'logo'))->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('the encoder refuses a corrupt picture with a clear message', function () {
    $path = tempnam(sys_get_temp_dir(), 'bad').'.jpg';
    file_put_contents($path, "\xFF\xD8\xFF\xE0broken");

    try {
        StoreImageEncoder::encode($path, 600, 'logo');
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('logo');
    }
});
