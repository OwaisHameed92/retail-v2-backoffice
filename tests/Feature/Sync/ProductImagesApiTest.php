<?php

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Actions\PurgeCompany;
use App\Domain\TillImages\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Sync\TillImageSamples as Img;
use Tests\Feature\TillData\TillFixtures;

/*
 * IMG-1, contract proposal docs/contracts/proposals/product-images: `PUT|DELETE sync/product-images/{productId}` and
 * `GET sync/product-images` behind TILL_PRODUCT_IMAGES. Every 2xx reply is also checked against the proposal's
 * schemas and every error against error-reply + the proposal's codes (ContractReplyGuard, after each test).
 */

beforeEach(function () {
    Storage::fake('public');
    config(['sync.product_images.enabled' => true]);
    $this->f = new SyncApiFixtures($this);
    $this->product = Img::product($this->f->company);
    // Another business (SyncApiFixtures without id_map rows): its till sends our ids for itself.
    $this->own = fn (?SyncApiFixtures $f) => $f === null ? [] : ['X-SSPOS-Company-Id' => $f->company->id, 'X-SSPOS-Branch-Id' => $f->leeds->id,
        'X-SSPOS-Register-Id' => $f->tills[TillFixtures::TILL_1]->id];
    $this->put = fn (string $productId, string $bytes, array $headers = [], ?SyncApiFixtures $f = null) => ($f ?? $this->f)->send('PUT', "/api/v1/sync/product-images/{$productId}", [
        'Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($bytes), 'X-SSPOS-Image-Sha256' => hash('sha256', $bytes), ...($this->own)($f), ...$headers,
    ], $bytes);
    $this->delete = fn (string $productId, ?SyncApiFixtures $f = null) => ($f ?? $this->f)->send('DELETE', "/api/v1/sync/product-images/{$productId}", ($this->own)($f));
    $this->list = fn (string $query = '', ?SyncApiFixtures $f = null) => ($f ?? $this->f)->send('GET', '/api/v1/sync/product-images'.($query === '' ? '' : '?'.$query), ($this->own)($f));
    $this->held = fn () => ProductImage::withoutCompanyScope()->where('company_id', $this->f->company->id)->get();
});

test('switched off (the default): every call is 501 image.not_available with Retry-After, before the body, and nothing is stored', function () {
    config(['sync.product_images.enabled' => false]);
    $jpeg = Img::jpeg();

    foreach ([($this->put)($this->product, $jpeg), ($this->delete)($this->product), ($this->list)()] as $response) {
        $response->assertStatus(501)->assertJsonPath('code', 'image.not_available')->assertJsonPath('retryAfterSeconds', 3600)->assertHeader('Retry-After', '3600');
    }

    // An unknown key still learns nothing: 401 first.
    ($this->put)($this->product, $jpeg, ['Authorization' => 'Bearer sspos_sync_'.str_repeat('x', 40)])->assertStatus(401);
    expect(ProductImage::withoutCompanyScope()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('the sync key and §3 headers are required, as on every sync call', function () {
    $jpeg = Img::jpeg();

    ($this->put)($this->product, $jpeg, ['Authorization' => null])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');
    ($this->put)($this->product, $jpeg, ['X-SSPOS-Branch-Id' => '01K5T0Q8C4000000000000B009'])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');
    ($this->put)($this->product, $jpeg, ['X-SSPOS-Contract' => '2'])->assertStatus(409)->assertJsonPath('code', 'contract.unsupported');
    ($this->put)($this->product, $jpeg, ['X-SSPOS-App-Version' => null])->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    ($this->list)('', null)->assertOk();
    expect(($this->held)())->toHaveCount(0);
});

test('a first picture is stored as a re-encoded web copy under till-images/{company}/{product}-{hash16}', function () {
    $jpeg = Img::jpeg(64, 48);
    $hash = hash('sha256', $jpeg);

    ($this->put)($this->product, $jpeg)->assertOk()
        ->assertJsonPath('productId', $this->product)->assertJsonPath('imageHash', $hash)->assertJsonPath('result', 'stored');

    $image = ($this->held)()->sole();
    $stored = Storage::disk('public')->get($image->path);

    expect($image->path)->toBe("till-images/{$this->f->company->id}/{$this->product}-".substr($hash, 0, 16).'.webp')
        ->and($image->hash)->toBe($hash)->and($image->source->value)->toBe('till')
        ->and([$image->width, $image->height, $image->bytes])->toBe([64, 48, strlen((string) $stored)])
        ->and(getimagesizefromstring((string) $stored)['mime'])->toBe('image/webp');

    // PNG and WebP are taken too.
    $png = Img::png();
    $webp = Img::webp();
    ($this->put)(Img::product($this->f->company, 'Milk'), $png, ['Content-Type' => 'image/png'])->assertOk()->assertJsonPath('result', 'stored');
    ($this->put)(Img::product($this->f->company, 'Eggs'), $webp, ['Content-Type' => 'image/webp; charset=binary'])->assertOk()->assertJsonPath('result', 'stored');
});

test('the same picture again is 200 unchanged and writes nothing (idempotent by hash)', function () {
    $jpeg = Img::jpeg();
    ($this->put)($this->product, $jpeg)->assertOk();
    $before = ($this->held)()->sole();
    $files = Storage::disk('public')->allFiles();
    $this->travel(5)->minutes();

    ($this->put)($this->product, $jpeg)->assertOk()->assertJsonPath('result', 'unchanged')
        ->assertJsonPath('updatedAt', $before->updated_at->utc()->format('Y-m-d\TH:i:s\Z'));

    $after = ($this->held)()->sole();
    expect($after->updated_at->equalTo($before->updated_at))->toBeTrue()->and(Storage::disk('public')->allFiles())->toBe($files);
});

test('a different picture replaces the old one: one row, the old file removed', function () {
    ($this->put)($this->product, Img::jpeg(40, 30, 200))->assertOk();
    $old = ($this->held)()->sole()->path;

    $new = Img::jpeg(50, 50, 20);
    ($this->put)($this->product, $new)->assertOk()->assertJsonPath('result', 'replaced')->assertJsonPath('imageHash', hash('sha256', $new));

    $image = ($this->held)()->sole();
    expect($image->path)->not->toBe($old)->and(Storage::disk('public')->exists($old))->toBeFalse()
        ->and(Storage::disk('public')->exists($image->path))->toBeTrue()->and([$image->width, $image->height])->toBe([50, 50]);
});

test('delete removes the till picture and its file; again is 200 none', function () {
    ($this->put)($this->product, Img::jpeg())->assertOk();
    $path = ($this->held)()->sole()->path;

    ($this->delete)($this->product)->assertOk()->assertJsonPath('result', 'deleted')->assertJsonPath('productId', $this->product);
    expect(($this->held)())->toHaveCount(0)->and(Storage::disk('public')->exists($path))->toBeFalse();

    ($this->delete)($this->product)->assertOk()->assertJsonPath('result', 'none');
});

test('isolation: a business never stores, deletes or lists pictures of another business\'s products (404 like an unknown one)', function () {
    $other = new SyncApiFixtures($this, mapTillIds: false);
    $theirs = Img::product($other->company, 'Their bread');
    ($this->put)($theirs, Img::jpeg(), [], $other)->assertOk();

    ($this->put)($theirs, Img::jpeg(30, 30))->assertStatus(404)->assertJsonPath('code', 'product.not_found')->assertJsonPath('rejectedKey', $theirs);
    ($this->delete)($theirs)->assertStatus(404)->assertJsonPath('code', 'product.not_found');
    ($this->list)()->assertOk()->assertJsonPath('images', []);

    ($this->put)(Ulid::new(), Img::jpeg())->assertStatus(404)->assertJsonPath('code', 'product.not_found');
    DB::table('products')->where('id', $this->product)->update(['deleted_at' => now()]);
    ($this->put)($this->product, Img::jpeg())->assertStatus(404);

    expect(($this->held)())->toHaveCount(0)
        ->and(ProductImage::withoutCompanyScope()->where('company_id', $other->company->id)->sole()->product_id)->toBe($theirs);
    ($this->list)('', $other)->assertOk()->assertJsonPath('images.0.productId', $theirs);
});

test('limits and types: size, type, hash, pixels and unreadable bytes are refused with the proposal codes', function () {
    $jpeg = Img::jpeg();
    $tooBig = str_repeat('x', 2 * 1024 * 1024 + 1);

    ($this->put)($this->product, $tooBig)->assertStatus(413)->assertJsonPath('code', 'image.too_large')->assertJsonPath('rejectedKey', $this->product);
    // A Content-Length that lies is caught when the bytes are read.
    ($this->put)($this->product, $tooBig, ['Content-Length' => '1000'])->assertStatus(413)->assertJsonPath('code', 'image.too_large');
    ($this->put)($this->product, Img::gif(), ['Content-Type' => 'image/gif'])->assertStatus(415)->assertJsonPath('code', 'image.unsupported_type');
    ($this->put)($this->product, Img::gif())->assertStatus(415)->assertJsonPath('code', 'image.unsupported_type');
    ($this->put)($this->product, Img::png())->assertStatus(415)->assertJsonPath('code', 'image.unsupported_type');
    ($this->put)($this->product, $jpeg, ['X-SSPOS-Image-Sha256' => hash('sha256', 'something else')])->assertStatus(422)->assertJsonPath('code', 'image.hash_mismatch');
    ($this->put)($this->product, Img::jpeg(2001, 20))->assertStatus(422)->assertJsonPath('code', 'image.dimensions');
    ($this->put)($this->product, Img::jpeg(15, 40))->assertStatus(422)->assertJsonPath('code', 'image.dimensions');
    ($this->put)($this->product, substr($jpeg, 0, 200))->assertStatus(422)->assertJsonPath('code', 'image.invalid');
    ($this->put)($this->product, 'not a picture at all')->assertStatus(422)->assertJsonPath('code', 'image.invalid');
    ($this->put)($this->product, $jpeg, ['X-SSPOS-Image-Sha256' => null])->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    ($this->put)($this->product, $jpeg, ['Content-Encoding' => 'gzip'])->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    ($this->put)('not-a-ulid', $jpeg)->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    $this->f->send('PUT', "/api/v1/sync/product-images/{$this->product}?key=x", ['Content-Type' => 'image/jpeg'], $jpeg)->assertStatus(400);

    expect(($this->held)())->toHaveCount(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('EXIF is stripped and its orientation applied; a large picture is kept at 1200 px on its longest side', function () {
    $jpeg = Img::jpegWithExif(60, 20, 6);
    expect(exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg))['Make'] ?? null)->toBe('SecretCam GPS 53.79N');

    ($this->put)($this->product, $jpeg)->assertOk();
    $image = ($this->held)()->sole();
    $stored = (string) Storage::disk('public')->get($image->path);

    // Orientation 6 = turn 90° clockwise: 60×20 becomes 20×60.
    expect([$image->width, $image->height])->toBe([20, 60])
        ->and($stored)->not->toContain('SecretCam')->not->toContain('Exif');

    // The white top-left block of the file is top-right once turned upright.
    $gd = imagecreatefromstring($stored);
    $white = fn (int $x, int $y) => (imagecolorat($gd, $x, $y) >> 16 & 0xFF) > 230;
    expect($white(18, 3))->toBeTrue()->and($white(2, 3))->toBeFalse()->and($white(18, 50))->toBeFalse();

    $big = Img::product($this->f->company, 'Big');
    ($this->put)($big, Img::png(1800, 900), ['Content-Type' => 'image/png'])->assertOk();
    $image = ProductImage::withoutCompanyScope()->where('product_id', $big)->sole();
    expect([$image->width, $image->height])->toBe([1200, 600])
        ->and(getimagesizefromstring((string) Storage::disk('public')->get($image->path))[0])->toBe(1200);
});

test('the list pages by product id with after / max, only this business\'s till pictures', function () {
    $ids = collect(range(1, 3))->map(fn () => Img::product($this->f->company, 'P'))->sort()->values()->all();

    foreach ($ids as $i => $id) {
        ($this->put)($id, Img::jpeg(20 + $i, 20))->assertOk();
    }

    $first = ($this->list)('max=2')->assertOk()->assertJsonPath('hasMore', true)->assertJsonPath('nextAfter', $ids[1]);
    expect(array_column($first->json('images'), 'productId'))->toBe([$ids[0], $ids[1]])
        ->and($first->json('images.0.imageHash'))->toBe(($this->held)()->firstWhere('product_id', $ids[0])->hash);

    ($this->list)('max=2&after='.$ids[1])->assertOk()->assertJsonPath('hasMore', false)->assertJsonPath('nextAfter', null)
        ->assertJsonPath('images.0.productId', $ids[2])->assertJsonCount(1, 'images');
    ($this->list)()->assertOk()->assertJsonCount(3, 'images');

    foreach (['max=0', 'max=5001', 'max=abc', 'after=short', 'after[]=x'] as $bad) {
        ($this->list)($bad)->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    }
});

test('its own rate limit per sync key: over it is 429 rate.limited with Retry-After', function () {
    config(['sync.product_images.per_minute' => 6]);

    foreach (range(1, 6) as $i) {
        ($this->list)()->assertOk();
    }

    ($this->list)()->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    // Bradford's key has its own count.
    $this->f->send('GET', '/api/v1/sync/product-images', [], null, true)->assertOk();
});

test('purging a business removes its till pictures (rows and files), and nobody else\'s', function () {
    $other = new SyncApiFixtures($this, mapTillIds: false);
    ($this->put)($this->product, Img::jpeg())->assertOk();
    ($this->put)(Img::product($other->company), Img::jpeg(), [], $other)->assertOk();

    app(PurgeCompany::class)->handle($this->f->company, force: true);

    expect(Storage::disk('public')->allFiles("till-images/{$this->f->company->id}"))->toBe([])
        ->and(ProductImage::withoutCompanyScope()->where('company_id', $this->f->company->id)->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles("till-images/{$other->company->id}"))->toHaveCount(1);
});
