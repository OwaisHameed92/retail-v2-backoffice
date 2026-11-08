<?php

namespace App\Domain\Pharmacy\Actions;

use App\Domain\Pharmacy\Models\DmdRelease;
use App\Domain\Pharmacy\Support\DmdErrors;
use App\Domain\Pharmacy\Support\DmdReleaseStore;
use App\Domain\Shared\Exceptions\ApiException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET {downloadUrl}` (W4): the stored NHSBSA zip, unchanged and whole (no Range), for a link the signature check
 * (GuardDmdServer) let through. A release pruned since the manifest, or a name we never held → 404 dmd.no_release.
 * Packages are immutable (a name is never reused for other bytes): `Cache-Control: public, max-age=31536000,
 * immutable`, the exact Content-Length and the SHA-256 as ETag.
 */
class OpenDmdPackage
{
    /**
     * @throws ApiException
     */
    public function handle(string $archiveFileName): StreamedResponse
    {
        $release = DmdRelease::query()->where('archive_file_name', $archiveFileName)->first();

        if ($release === null || ! DmdReleaseStore::holds($release)) {
            throw DmdErrors::noRelease();
        }

        $disk = DmdReleaseStore::disk();
        $path = $release->path;

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $release->size_bytes,
            'Content-Disposition' => 'attachment; filename="'.$release->archive_file_name.'"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$release->sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
