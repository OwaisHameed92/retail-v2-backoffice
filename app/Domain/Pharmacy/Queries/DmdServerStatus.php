<?php

namespace App\Domain\Pharmacy\Queries;

use App\Domain\Pharmacy\Enums\DmdFetchOutcome;
use App\Domain\Pharmacy\Models\DmdFetchRun;
use App\Domain\Pharmacy\Models\DmdRelease;
use App\Domain\Pharmacy\Support\DmdSwitch;

/**
 * The admin Till health page's read-only "dm+d release server" card (W4): the switch, whether a TRUD key is set
 * (yes / no only: the key itself never leaves the server), the release tills are served (week, date, size, the
 * first 12 characters of its SHA-256), the last run and the last error (already redacted when stored).
 *
 * @phpstan-type DmdStatus array{enabled: bool, keyConfigured: bool, release: array{name: string, week: string, releaseDate: string, sizeBytes: int, sha256: string, publishedAt: string|null}|null, lastRun: array{outcome: string, at: string|null}|null, lastError: array{message: string, at: string|null}|null}
 */
final class DmdServerStatus
{
    /**
     * @return DmdStatus
     */
    public static function compute(): array
    {
        $release = DmdRelease::newest();
        $lastRun = DmdFetchRun::query()->orderByDesc('started_at')->first();
        $lastError = DmdFetchRun::query()->where('outcome', DmdFetchOutcome::Failed->value)->orderByDesc('started_at')->first();

        return [
            'enabled' => DmdSwitch::enabled(),
            'keyConfigured' => DmdSwitch::hasTrudKey(),
            'release' => $release === null ? null : [
                'name' => $release->name,
                'week' => $release->week,
                'releaseDate' => $release->release_date->toDateString(),
                'sizeBytes' => $release->size_bytes,
                'sha256' => substr(strtoupper($release->sha256), 0, 12),
                'publishedAt' => $release->published_at->toIso8601ZuluString(),
            ],
            'lastRun' => $lastRun === null ? null : [
                'outcome' => $lastRun->outcome->value,
                'at' => ($lastRun->finished_at ?? $lastRun->started_at)->toIso8601ZuluString(),
            ],
            'lastError' => $lastError === null ? null : [
                'message' => DmdSwitch::redact((string) $lastError->error),
                'at' => $lastError->started_at->toIso8601ZuluString(),
            ],
        ];
    }
}
