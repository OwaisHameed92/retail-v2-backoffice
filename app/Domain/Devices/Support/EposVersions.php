<?php

namespace App\Domain\Devices\Support;

use App\Domain\Shared\Support\AppVersion;
use Illuminate\Support\Facades\DB;

/**
 * The EPOS versions tills reported on their last licence call (`X-SSPOS-App-Version`, kept in
 * `licences.last_app_version`), over live licences of every business. Versions are compared as semver with
 * AppVersion (0.1.9 < 0.1.85), never as text, so "older than" is worked out here over the few distinct versions and
 * handed to SQL as a list.
 */
final class EposVersions
{
    /** @var array<string, int>|null */
    private ?array $counts = null;

    /**
     * Each version seen with its number of tills, newest first.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $counts = DB::table('licences')
            ->whereNull('deleted_at')
            ->whereNotNull('live_register_id')
            ->whereNotNull('last_app_version')
            ->groupBy('last_app_version')
            ->selectRaw('last_app_version as version, count(*) as tills')
            ->pluck('tills', 'version')
            ->mapWithKeys(fn ($tills, $version) => [(string) $version => (int) $tills])
            ->all();

        uksort($counts, fn (string $a, string $b) => AppVersion::compare($b, $a));

        return $this->counts = $counts;
    }

    /** The newest valid version any till runs, or null when no till reported one. */
    public function highest(): ?string
    {
        foreach (array_keys($this->counts()) as $version) {
            if (AppVersion::isValid($version)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * The versions seen that are older than `$gate` (an unreadable version counts as older).
     *
     * @return list<string>
     */
    public function below(string $gate): array
    {
        return array_values(array_filter(array_map('strval', array_keys($this->counts())), fn (string $version) => AppVersion::compare($version, $gate) < 0));
    }

    /**
     * For the page: versions newest first with their till counts.
     *
     * @return list<array{version: string, tills: int}>
     */
    public function toList(): array
    {
        $out = [];

        foreach ($this->counts() as $version => $tills) {
            $out[] = ['version' => (string) $version, 'tills' => $tills];
        }

        return $out;
    }
}
