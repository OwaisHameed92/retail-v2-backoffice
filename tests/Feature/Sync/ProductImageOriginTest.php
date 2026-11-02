<?php

use App\Domain\TillImages\Enums\ProductImageOrigin;
use App\Domain\TillImages\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Sync\TillImageSamples as Img;

/*
 * IMG-2 (EPOS answer 2026-11-02, till 0.1.83): `X-SSPOS-Image-Source` on `PUT sync/product-images/{productId}` is
 * stored as the picture's origin. Lenient: missing, empty or unknown values are `unknown`, never an error. The same
 * bytes again with another known origin change only the origin; the list reply carries it as an added member.
 */

beforeEach(function () {
    Storage::fake('public');
    config(['sync.product_images.enabled' => true]);
    $this->f = new SyncApiFixtures($this);
    $this->product = Img::product($this->f->company);
    $this->put = fn (string $bytes, array $headers = [], ?string $productId = null) => $this->f->send('PUT', '/api/v1/sync/product-images/'.($productId ?? $this->product), [
        'Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($bytes), 'X-SSPOS-Image-Sha256' => hash('sha256', $bytes), ...$headers,
    ], $bytes);
    $this->origin = fn (?string $productId = null) => ProductImage::withoutCompanyScope()->where('company_id', $this->f->company->id)
        ->where('product_id', $productId ?? $this->product)->sole()->origin;
});

test('each header value is stored as the origin; case and spaces do not matter', function (string $header, ProductImageOrigin $stored) {
    ($this->put)(Img::jpeg(), ['X-SSPOS-Image-Source' => $header])->assertOk()->assertJsonPath('result', 'stored');

    expect(($this->origin)())->toBe($stored);
})->with([
    'own' => ['own', ProductImageOrigin::Own],
    'library' => ['library', ProductImageOrigin::Library],
    'found' => ['found', ProductImageOrigin::Found],
    'unknown' => ['unknown', ProductImageOrigin::Unknown],
    'upper case, spaces' => ['  LIBRARY ', ProductImageOrigin::Library],
    'mixed case' => ['Own', ProductImageOrigin::Own],
]);

test('a missing, empty or unrecognised header is stored as unknown, never refused', function (?string $header) {
    ($this->put)(Img::jpeg(), $header === null ? [] : ['X-SSPOS-Image-Source' => $header])->assertOk()->assertJsonPath('result', 'stored');

    expect(($this->origin)())->toBe(ProductImageOrigin::Unknown);
})->with([
    'missing' => [null],
    'empty' => [''],
    'garbage' => ['bing-thumbnail'],
    'a number' => ['3'],
    'very long' => [str_repeat('x', 300)],
]);

test('the same bytes with another known origin: 200 unchanged, only the origin changes (no new file, same updatedAt)', function () {
    $jpeg = Img::jpeg();
    ($this->put)($jpeg)->assertOk()->assertJsonPath('result', 'stored');
    $before = ProductImage::withoutCompanyScope()->sole();
    $files = Storage::disk('public')->allFiles();
    $this->travel(5)->minutes();

    ($this->put)($jpeg, ['X-SSPOS-Image-Source' => 'own'])->assertOk()->assertJsonPath('result', 'unchanged')
        ->assertJsonPath('updatedAt', $before->updated_at->utc()->format('Y-m-d\TH:i:s\Z'));

    $after = ProductImage::withoutCompanyScope()->sole();
    expect($after->origin)->toBe(ProductImageOrigin::Own)
        ->and($after->path)->toBe($before->path)
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe($files);

    // Another known origin replaces it; unknown (or no header) never overwrites a known one.
    ($this->put)($jpeg, ['X-SSPOS-Image-Source' => 'found'])->assertOk()->assertJsonPath('result', 'unchanged');
    expect(($this->origin)())->toBe(ProductImageOrigin::Found);
    ($this->put)($jpeg, ['X-SSPOS-Image-Source' => 'unknown'])->assertOk()->assertJsonPath('result', 'unchanged');
    ($this->put)($jpeg)->assertOk()->assertJsonPath('result', 'unchanged');
    expect(($this->origin)())->toBe(ProductImageOrigin::Found);
});

test('a replaced picture takes the origin sent with it, unknown included', function () {
    ($this->put)(Img::jpeg(40, 30, 200), ['X-SSPOS-Image-Source' => 'own'])->assertOk();
    ($this->put)(Img::jpeg(50, 50, 20), ['X-SSPOS-Image-Source' => 'library'])->assertOk()->assertJsonPath('result', 'replaced');
    expect(($this->origin)())->toBe(ProductImageOrigin::Library);

    ($this->put)(Img::jpeg(60, 50, 90))->assertOk()->assertJsonPath('result', 'replaced');
    expect(($this->origin)())->toBe(ProductImageOrigin::Unknown);
});

test('the list carries each picture\'s origin', function () {
    $second = Img::product($this->f->company, 'Milk');
    ($this->put)(Img::jpeg(), ['X-SSPOS-Image-Source' => 'library'])->assertOk();
    ($this->put)(Img::jpeg(30, 30), [], $second)->assertOk();

    $images = collect($this->f->send('GET', '/api/v1/sync/product-images')->assertOk()->json('images'))->pluck('origin', 'productId')->all();

    expect($images)->toEqual([$this->product => 'library', $second => 'unknown']);
});
