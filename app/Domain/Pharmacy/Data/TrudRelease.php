<?php

namespace App\Domain\Pharmacy\Data;

use Carbon\CarbonImmutable;
use RuntimeException;
use SensitiveParameter;

/**
 * TRUD item 24's latest release, as `GET /keys/{key}/items/24/releases?latest` lists it (W4). `archiveFileUrl`
 * holds our key in its path: it is only used to download and is never logged, stored or shown.
 */
final readonly class TrudRelease
{
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.zip$/';

    public function __construct(
        public string $name,
        public string $releaseDate,
        public string $archiveFileName,
        #[SensitiveParameter] public string $archiveFileUrl,
        public ?int $sizeBytes,
        public string $sha256,
    ) {}

    /**
     * @param  array<mixed>  $release  one entry of TRUD's `releases`
     *
     * @throws RuntimeException when TRUD's entry is unusable (message never holds the key)
     */
    public static function fromTrud(array $release): self
    {
        $file = (string) ($release['archiveFileName'] ?? '');
        $sha = strtolower((string) ($release['archiveFileSha256'] ?? ''));
        $date = (string) ($release['releaseDate'] ?? '');
        $url = (string) ($release['archiveFileUrl'] ?? '');
        $size = $release['archiveFileSizeBytes'] ?? null;

        if (preg_match(self::NAME, $file) !== 1) {
            throw new RuntimeException('TRUD listed an archive file name the portal does not accept.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new RuntimeException("TRUD listed no valid archiveFileSha256 for {$file}.");
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new RuntimeException("TRUD listed no valid releaseDate for {$file}.");
        }

        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException("TRUD listed no https archiveFileUrl for {$file}.");
        }

        return new self(
            mb_substr(trim((string) ($release['name'] ?? '')), 0, 150) ?: $file,
            $date,
            $file,
            $url,
            is_numeric($size) && (int) $size > 0 ? (int) $size : null,
            $sha,
        );
    }

    /** `nhsbsa_dmd_10.3.0_20261026000001` for `nhsbsa_dmd_10.3.0_20261026000001.zip` (the contract's releaseId). */
    public function releaseId(): string
    {
        return substr($this->archiveFileName, 0, -4);
    }

    /** ISO week of NHSBSA's release date, e.g. `2026-W44`. */
    public function week(): string
    {
        return CarbonImmutable::parse($this->releaseDate, 'UTC')->format('o-\WW');
    }
}
