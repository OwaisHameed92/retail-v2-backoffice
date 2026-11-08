<?php

namespace Tests\Feature\Pharmacy;

use App\Domain\Pharmacy\Models\DmdRelease;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * W4 test helpers: a fake TRUD key, NHSBSA-style release names and zips, a fake TRUD (Http::fake, never the real
 * service) and a release published straight onto the faked dm+d disk.
 */
final class DmdFixtures
{
    public const KEY = 'trud-test-key-5f1c9a7e3b2d48a6b0c1';

    public const TRUD = 'https://isd.digital.nhs.uk/trud/api/v1';

    /** `nhsbsa_dmd_10.3.0_20261026000001.zip` style name for a Monday. */
    public static function archive(string $date, string $version = '10.3.0'): string
    {
        return 'nhsbsa_dmd_'.$version.'_'.str_replace('-', '', $date).'000001.zip';
    }

    /** Some zip-like bytes, different per release. */
    public static function zip(string $date): string
    {
        return "PK\x03\x04".str_repeat("dm+d {$date} ", 2000);
    }

    /** Switch on with our key (the tests' default is off, as in production). */
    public static function on(): void
    {
        config(['pharmacy.dmd.enabled' => true, 'pharmacy.dmd.trud_key' => self::KEY, 'app.url' => 'http://portal.test']);
    }

    /**
     * TRUD item 24's release list naming one release, and its zip. `$sha` overrides archiveFileSha256.
     *
     * @return array<string, mixed> the release entry
     */
    public static function trudRelease(string $date, ?string $sha = null, ?string $bytes = null, string $version = '10.3.0'): array
    {
        $bytes ??= self::zip($date);
        $archive = self::archive($date, $version);

        return [
            'id' => $archive,
            'name' => "Release {$version}",
            'releaseDate' => $date,
            'archiveFileUrl' => 'https://isd.digital.nhs.uk/download/api/v1/keys/'.self::KEY.'/content/items/24/'.$archive,
            'archiveFileName' => $archive,
            'archiveFileSizeBytes' => strlen($bytes),
            'archiveFileSha256' => $sha ?? strtoupper(hash('sha256', $bytes)),
            'archiveFileLastModifiedTimestamp' => $date.'T05:12:00Z',
        ];
    }

    /** Fakes TRUD: the list names the release of `$date`; its download answers `$bytes`. */
    public static function fakeTrud(string $date, ?string $sha = null, ?string $bytes = null, int $listStatus = 200): void
    {
        $bytes ??= self::zip($date);
        $release = self::trudRelease($date, $sha, $bytes);

        Http::fake([
            self::TRUD.'/keys/*' => Http::response($listStatus === 200 ? ['apiVersion' => '1', 'releases' => [$release], 'httpStatus' => 200, 'message' => 'OK'] : ['message' => 'Bad key'], $listStatus),
            'https://isd.digital.nhs.uk/download/*' => Http::response($bytes, 200, ['Content-Type' => 'application/zip']),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    /** How many TRUD downloads (not list calls) were sent. */
    public static function downloads(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), '/download/')));
    }

    /** A release stored on the (faked) dm+d disk and published, as FetchDmdRelease leaves it. */
    public static function publish(string $date, ?string $bytes = null, ?CarbonImmutable $at = null): DmdRelease
    {
        $bytes ??= self::zip($date);
        $archive = self::archive($date);
        $releaseId = substr($archive, 0, -4);
        $path = "pharmacy/dmd/{$releaseId}/{$archive}";
        Storage::disk('local')->put($path, $bytes);

        return DmdRelease::query()->create([
            'release_id' => $releaseId, 'name' => 'Release 10.3.0', 'release_date' => $date,
            'week' => CarbonImmutable::parse($date)->format('o-\WW'), 'archive_file_name' => $archive, 'path' => $path,
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'published_at' => $at ?? CarbonImmutable::parse($date.' 07:12:00'),
        ]);
    }
}
