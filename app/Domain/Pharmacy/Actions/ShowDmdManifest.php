<?php

namespace App\Domain\Pharmacy\Actions;

use App\Domain\Pharmacy\Models\DmdRelease;
use App\Domain\Pharmacy\Support\DmdErrors;
use App\Domain\Pharmacy\Support\DmdPackageLink;
use App\Domain\Pharmacy\Support\DmdReleaseStore;
use App\Domain\Pharmacy\Support\PharmacyBranch;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use Illuminate\Support\Facades\Log;

/**
 * `GET /api/v1/pharmacy/dmd/latest` (W4, PORTAL-CHANGES-pharmacy.md item 12) for a till whose sync key was checked:
 *
 * - the key's shop is not a pharmacy (PharmacyBranch) → 403 dmd.not_pharmacy;
 * - no release held (or its zip is missing) → 404 dmd.no_release;
 * - else the manifest {releaseId, name, releaseDate, week, archiveFileName, downloadUrl, sizeBytes, sha256,
 *   publishedAt}; downloadUrl is a fresh https signed link (DmdPackageLink, ≥ 24 h), sha256 upper-case hex as in
 *   the contract's sample.
 *
 * The switch and rate limit are the route's middleware (GuardDmdServer).
 */
class ShowDmdManifest
{
    /**
     * @return array{releaseId: string, name: string, releaseDate: string, week: string, archiveFileName: string, downloadUrl: string, sizeBytes: int, sha256: string, publishedAt: string}
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller): array
    {
        if (! PharmacyBranch::is($caller->company, $caller->branch)) {
            throw DmdErrors::notPharmacy();
        }

        $release = DmdRelease::newest() ?? throw DmdErrors::noRelease();

        if (! DmdReleaseStore::holds($release)) {
            Log::warning('dm+d manifest: the newest release has no full zip on disk', ['releaseId' => $release->release_id]);

            throw DmdErrors::noRelease();
        }

        return [
            'releaseId' => $release->release_id,
            'name' => $release->name,
            'releaseDate' => $release->release_date->toDateString(),
            'week' => $release->week,
            'archiveFileName' => $release->archive_file_name,
            'downloadUrl' => DmdPackageLink::for($release),
            'sizeBytes' => $release->size_bytes,
            'sha256' => strtoupper($release->sha256),
            'publishedAt' => $release->published_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
