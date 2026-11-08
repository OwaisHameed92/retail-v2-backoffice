<?php

namespace App\Domain\Pharmacy\Support;

use App\Domain\Pharmacy\Data\TrudRelease;
use App\Domain\Pharmacy\Models\DmdRelease;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The dm+d zips on disk (W4): `{path}/{releaseId}/{archiveFileName}` (NHSBSA's zip, unchanged) and
 * `{path}/{releaseId}/ATTRIBUTION.txt` (the Open Government Licence v3.0 attribution).
 *
 * Publishing is atomic for the till: the zip is written and its stored size checked first, and only then is the
 * `dmd_releases` row inserted in one write. The manifest reads that row, so it never names a zip that is not fully
 * stored. Pruning deletes the row first, then the folder.
 */
final class DmdReleaseStore
{
    public static function disk(): Filesystem
    {
        return Storage::disk(DmdSwitch::disk());
    }

    /** Whether the release's zip is on disk at its full size. */
    public static function holds(DmdRelease $release): bool
    {
        $disk = self::disk();

        return $disk->exists($release->path) && $disk->size($release->path) === $release->size_bytes;
    }

    /**
     * Stores a checked local zip under its own name and publishes it.
     *
     * @throws RuntimeException
     */
    public static function publish(TrudRelease $release, string $localFile, int $size, string $sha256, CarbonImmutable $now): DmdRelease
    {
        $disk = self::disk();
        $folder = DmdSwitch::path().'/'.$release->releaseId();
        $path = $folder.'/'.$release->archiveFileName;

        if ($disk->putFileAs($folder, new File($localFile), $release->archiveFileName) === false || $disk->size($path) !== $size) {
            throw new RuntimeException("{$release->archiveFileName} could not be stored in full.");
        }

        $disk->put($folder.'/ATTRIBUTION.txt', self::attribution($release, $now));

        return DmdRelease::query()->updateOrCreate(['release_id' => $release->releaseId()], [
            'name' => $release->name,
            'release_date' => $release->releaseDate,
            'week' => $release->week(),
            'archive_file_name' => $release->archiveFileName,
            'path' => $path,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'published_at' => $now,
        ]);
    }

    /**
     * Keeps the newest release and those of the previous `keep_weeks` weeks; removes older rows and their folders,
     * and folders no row names (a run that stopped between storing and publishing).
     *
     * @return int releases removed
     */
    public static function prune(): int
    {
        $newest = DmdRelease::newest();

        if ($newest === null) {
            return 0;
        }

        $cutoff = $newest->release_date->subWeeks(DmdSwitch::keepWeeks())->toDateString();
        $old = DmdRelease::query()->whereKeyNot($newest->id)->where('release_date', '<', $cutoff)->get();
        $disk = self::disk();

        foreach ($old as $release) {
            $release->delete();
            $disk->deleteDirectory($release->folder());
            Log::info('dm+d release removed', ['releaseId' => $release->release_id]);
        }

        $kept = DmdRelease::query()->pluck('path')->map(fn (string $path) => dirname($path))->all();

        foreach ($disk->directories(DmdSwitch::path()) as $folder) {
            if (! in_array($folder, $kept, true)) {
                $disk->deleteDirectory($folder);
            }
        }

        return $old->count();
    }

    private static function attribution(TrudRelease $release, CarbonImmutable $now): string
    {
        return implode("\n", [
            "NHSBSA Dictionary of medicines and devices (dm+d), {$release->name} ({$release->releaseDate})",
            "File: {$release->archiveFileName} (unchanged, as published on NHS TRUD, item 24)",
            'Downloaded by the SSPOS portal on '.$now->utc()->toIso8601ZuluString(),
            '',
            DmdSwitch::attribution(),
            'https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/',
            '',
        ]);
    }
}
